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
    static targets = ['terminal', 'submit', 'status', 'eye', 'mirror'];
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
        // Plages [start, end] du texte de l'IA, pour colorer le miroir en ambre.
        this.aiRanges = [];
        this.currentRange = null;
        this.boundInput = () => this.handleTyping();
        this.boundScroll = () => this.syncMirrorScroll();
        this.setEyeState('idle');
        this.terminalTarget.addEventListener('input', this.boundInput);
        this.terminalTarget.addEventListener('scroll', this.boundScroll);
        this.renderMirror();
    }

    disconnect() {
        this.cancel();
        this.terminalTarget.removeEventListener('input', this.boundInput);
        this.terminalTarget.removeEventListener('scroll', this.boundScroll);
        if (this.typingTimeout) {
            clearTimeout(this.typingTimeout);
        }
    }

    /**
     * Réaction de l'œil à la frappe : passe en « typing » puis revient au repos.
     */
    handleTyping() {
        // Le texte saisi (zone utilisateur) doit être répercuté dans le miroir.
        this.renderMirror();
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
        // Nouvelle plage pour la réponse de l'IA (colorée en ambre dans le miroir).
        const start = this.terminalTarget.value.length;
        this.currentRange = { start, end: start };
        this.aiRanges.push(this.currentRange);
        this.renderMirror();

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
     * En fin de streaming : ajoute deux retours chariot après le texte de l'IA
     * et place le curseur à la fin (dans le textarea), puis défile en bas.
     */
    placeCaretAtEnd() {
        const terminal = this.terminalTarget;
        // On normalise la fin puis on ajoute deux retours chariot.
        terminal.value = `${terminal.value.replace(/\s+$/, '')}\n\n`;
        terminal.focus();
        const end = terminal.value.length;
        terminal.setSelectionRange(end, end);
        terminal.scrollTop = terminal.scrollHeight;
        // Le transcript complet (demande + réponse) est désormais validé.
        this.committedLength = terminal.value.length;
        this.renderMirror();
        this.syncMirrorScroll();
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
            if (this.currentRange) {
                this.currentRange.end = this.terminalTarget.value.length;
            }
            this.renderMirror();
        }
    }

    /**
     * Reconstruit le miroir coloré (texte utilisateur en vert, texte de l'IA en
     * ambre) à partir de la valeur du textarea et des plages d'IA mémorisées.
     */
    renderMirror() {
        if (!this.hasMirrorTarget) {
            return;
        }
        const value = this.terminalTarget.value;
        let html = '';
        let cursor = 0;
        for (const range of this.aiRanges) {
            if (range.end <= range.start) {
                continue;
            }
            html += this.escapeHtml(value.slice(cursor, range.start));
            html += `<span class="hal-ai">${this.escapeHtml(value.slice(range.start, range.end))}</span>`;
            cursor = range.end;
        }
        html += this.escapeHtml(value.slice(cursor));
        // Garde une hauteur pour l'éventuelle dernière ligne vide.
        if (value.endsWith('\n')) {
            html += ' ';
        }
        this.mirrorTarget.innerHTML = html;
        this.syncMirrorScroll();
    }

    /**
     * Synchronise le défilement du miroir avec celui du textarea.
     */
    syncMirrorScroll() {
        if (!this.hasMirrorTarget) {
            return;
        }
        this.mirrorTarget.scrollTop = this.terminalTarget.scrollTop;
        this.mirrorTarget.scrollLeft = this.terminalTarget.scrollLeft;
    }

    escapeHtml(text) {
        return text
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;');
    }

    /**
     * Vide le terminal et la zone de sortie (seul moyen d'effacer le texte).
     */
    reset() {
        this.cancel();
        this.terminalTarget.value = '';
        this.committedLength = 0;
        this.answerLength = 0;
        this.aiRanges = [];
        this.currentRange = null;
        const output = document.getElementById('ai-output');
        if (output) {
            output.textContent = '';
        }
        this.setStatus('EN ATTENTE');
        this.setEyeState('idle');
        this.renderMirror();
        this.syncMirrorScroll();
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
