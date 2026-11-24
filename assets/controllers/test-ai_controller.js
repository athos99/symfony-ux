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
    static targets = ['terminal', 'submit', 'status'];
    static values = { streamUrl: String };

    connect() {
        this.abortController = null;
    }

    disconnect() {
        this.cancel();
    }

    /**
     * Envoi de la demande et ouverture du flux SSE.
     */
    async send() {
        const request = this.terminalTarget.value.trim();
        if (!request || this.isStreaming) {
            return;
        }

        // On affiche la demande dans le terminal, suivie d'un retour à la ligne.
        this.terminalTarget.value = `${request}\n\n`;
        this.setStatus('Génération…');
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
            }
        } finally {
            this.isStreaming = false;
            this.submitTarget.disabled = false;
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
        }
    }

    /**
     * Recopie le contenu de #ai-output dans le textarea.
     */
    renderTerminal() {
        const output = document.getElementById('ai-output');
        if (output) {
            this.terminalTarget.value = output.textContent;
        }
    }

    /**
     * Vide le terminal et la zone de sortie.
     */
    reset() {
        this.cancel();
        this.terminalTarget.value = '';
        const output = document.getElementById('ai-output');
        if (output) {
            output.textContent = '';
        }
        this.setStatus('');
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
