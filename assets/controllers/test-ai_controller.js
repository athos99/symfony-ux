import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';

/**
 * Contrôleur Stimulus du terminal de chat IA.
 *
 * Rôle :
 *  - à l'envoi, POST la demande vers l'endpoint SSE (data-test-ai-*-value) ;
 *  - lit le flux SSE fragment par fragment (fetch + ReadableStream) ;
 *  - pour chaque événement, transmet le Turbo Stream à Turbo via
 *    renderStreamMessage(), ce qui met à jour #ai-output ;
 *  - recopie en direct le contenu de #ai-output dans le textarea (le terminal).
 *
 * On utilise fetch() plutôt que l'élément <turbo-stream-source> car ce dernier
 * ne gère que le GET (EventSource), alors qu'on a besoin d'envoyer un corps
 * JSON (la demande) via POST.
 */
export default class extends Controller {
    static targets = ['terminal', 'submit', 'status', 'eye'];
    static values = { streamUrl: String };

    connect() {
        this.abortController = null;
        this.typingTimeout = null;
        // Longueur du transcript déjà « validé » (demandes + réponses rendues).
        // La nouvelle demande est tout ce qui est saisi au-delà de cette longueur.
        this.committedLength = 0;
        // Nombre de caractères de la réponse en cours déjà recopiés dans le
        // textarea (le serveur pousse un texte cumulé : on n'ajoute que le delta).
        this.answerLength = 0;
        this.boundInput = () => this.handleTyping();
        this.setEyeState('idle');
        this.terminalTarget.addEventListener('input', this.boundInput);
    }

    disconnect() {
        this.cancel();
        this.terminalTarget.removeEventListener('input', this.boundInput);
        if (this.typingTimeout) {
            clearTimeout(this.typingTimeout);
        }
    }

    /**
     * Réaction de l'œil à la frappe : passe en « typing » puis revient au repos.
     */
    handleTyping() {
        if (this.isStreaming) {
            return;
        }
        this.setEyeState('typing');
        if (this.typingTimeout) {
            clearTimeout(this.typingTimeout);
        }
        this.typingTimeout = setTimeout(() => this.setEyeState('idle'), 700);
    }

    /**
     * Pilote l'état visuel de l'œil de HAL (idle / typing / thinking / error).
     */
    setEyeState(state) {
        if (this.hasEyeTarget) {
            this.eyeTarget.dataset.state = state;
        }
    }

    /**
     * Envoi de la demande et ouverture du flux SSE.
     */
    async send() {
        // La demande est la portion saisie au-delà du transcript déjà validé.
        const full = this.terminalTarget.value;
        const request = full.slice(this.committedLength).trim();
        if (!request || this.isStreaming) {
            return;
        }

        // On conserve tout le texte existant (jamais effacé) et on ajoute la
        // nouvelle demande, suivie d'une séparation.
        const base = full.replace(/\s+$/, '');
        this.terminalTarget.value = base ? `${base}\n\n` : '';
        this.answerLength = 0;

        const output = document.getElementById('ai-output');
        if (output) {
            output.textContent = '';
        }

        this.setStatus('Génération…');
        this.setEyeState('thinking');
        this.isStreaming = true;
        this.submitTarget.disabled = true;

        this.abortController = new AbortController();

        try {
            const response = await fetch(this.streamUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                },
                body: JSON.stringify({ message: request }),
                signal: this.abortController.signal,
            });

            if (!response.ok || !response.body) {
                throw new Error(`Réponse HTTP ${response.status}`);
            }

            await this.consume(response.body);
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.setStatus(`Erreur : ${error.message}`);
                this.setEyeState('error');
            }
        } finally {
            this.isStreaming = false;
            this.submitTarget.disabled = false;
            if (this.eyeTarget?.dataset.state === 'thinking') {
                this.setEyeState('idle');
            }
        }
    }

    /**
     * Lecture du flux SSE et rendu de chaque Turbo Stream.
     */
    async consume(body) {
        const reader = body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (true) {
            const { value, done } = await reader.read();
            if (done) {
                break;
            }

            buffer += decoder.decode(value, { stream: true });

            // Un événement SSE est séparé du suivant par une ligne vide.
            let boundary = buffer.indexOf('\n\n');
            while (boundary !== -1) {
                const rawEvent = buffer.slice(0, boundary);
                buffer = buffer.slice(boundary + 2);
                await this.processEvent(rawEvent);
                boundary = buffer.indexOf('\n\n');
            }
        }
    }

    /**
     * Traite un événement SSE brut (bloc « event: …\ndata: … »).
     */
    async processEvent(rawEvent) {
        let event = 'message';
        let data = '';

        for (const line of rawEvent.split('\n')) {
            if (line.startsWith('event:')) {
                event = line.slice(6).trim();
            } else if (line.startsWith('data:')) {
                // On retire le préfixe « data: » et un éventuel espace initial.
                data += line.slice(5).trimStart();
            }
        }

        if (!data) {
            return;
        }

        // On laisse Turbo appliquer le Turbo Stream (met à jour #ai-output).
        // renderStreamMessage est asynchrone (il attend un repaint) : on attend
        // donc le prochain frame avant de recopier le texte dans le terminal.
        renderStreamMessage(data);
        await new Promise((resolve) => requestAnimationFrame(resolve));

        // On recopie la réponse cumulée dans le textarea du terminal.
        this.renderTerminal();

        if (event === 'done') {
            this.setStatus('Terminé.');
            this.setEyeState('idle');
            this.placeCaretAtEnd();
        }
    }

    /**
     * En fin de streaming : ajoute un retour chariot et place le curseur en fin
     * de texte (dans le textarea), puis fait défiler jusqu'en bas.
     */
    placeCaretAtEnd() {
        const terminal = this.terminalTarget;
        if (!terminal.value.endsWith('\n')) {
            terminal.value += '\n';
        }
        terminal.focus();
        const end = terminal.value.length;
        terminal.setSelectionRange(end, end);
        terminal.scrollTop = terminal.scrollHeight;
        // Le transcript complet (demande + réponse) est désormais validé.
        this.committedLength = terminal.value.length;
    }

    /**
     * Recopie la réponse cumulée (#ai-output) dans le textarea en n'ajoutant
     * que le nouveau delta (le serveur renvoie du texte cumulé). L'opération
     * est idempotente : un appel supplémentaire avec le même contenu n'ajoute
     * rien, ce qui évite tout doublon.
     */
    renderTerminal() {
        const output = document.getElementById('ai-output');
        if (!output) {
            return;
        }
        const answer = output.textContent;
        if (answer.length > this.answerLength) {
            this.terminalTarget.value += answer.slice(this.answerLength);
            this.answerLength = answer.length;
            this.terminalTarget.scrollTop = this.terminalTarget.scrollHeight;
        }
    }

    /**
     * Vide le terminal et la zone de sortie (seul moyen d'effacer le texte).
     */
    reset() {
        this.cancel();
        this.terminalTarget.value = '';
        this.committedLength = 0;
        this.answerLength = 0;
        const output = document.getElementById('ai-output');
        if (output) {
            output.textContent = '';
        }
        this.setStatus('EN ATTENTE');
        this.setEyeState('idle');
    }

    /**
     * Annule la requête en cours, s'il y en a une.
     */
    cancel() {
        if (this.abortController) {
            this.abortController.abort();
            this.abortController = null;
        }
        this.isStreaming = false;
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = false;
        }
    }

    setStatus(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = message;
        }
    }
}
