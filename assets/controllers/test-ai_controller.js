import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import { marked } from 'marked';

// Afficheur 7 segments (style LED années 70) : segments actifs par chiffre.
const SEGMENTS = {
    0: ['a', 'b', 'c', 'd', 'e', 'f'],
    1: ['b', 'c'],
    2: ['a', 'b', 'g', 'e', 'd'],
    3: ['a', 'b', 'g', 'c', 'd'],
    4: ['f', 'g', 'b', 'c'],
    5: ['a', 'f', 'g', 'c', 'd'],
    6: ['a', 'f', 'g', 'e', 'c', 'd'],
    7: ['a', 'b', 'c'],
    8: ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
    9: ['a', 'b', 'c', 'd', 'f', 'g'],
};
const ALL_SEGMENTS = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];

/**
 * Échappe le HTML brut (la réponse du modèle n'est pas de confiance).
 */
function escapeHtml(text) {
    return text
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
}
// Coordonnées [x1, y1, x2, y2] de chaque segment (viewBox 0 0 10 18).
const SEGMENT_COORDS = {
    a: [1.5, 1, 8.5, 1],
    b: [9, 1.5, 9, 8],
    c: [9, 10, 9, 16.5],
    d: [1.5, 17, 8.5, 17],
    e: [1, 10, 1, 16.5],
    f: [1, 1.5, 1, 8],
    g: [1.5, 9, 8.5, 9],
};

// Options de rendu Markdown : on échappe le HTML brut (la réponse du modèle
// n'est pas de confiance) et on conserve les retours à la ligne simples.
marked.use({
    breaks: true,
    gfm: true,
    renderer: {
        html({ text }) {
            return escapeHtml(text);
        },
    },
});

/**
 * Contrôleur Stimulus du terminal de chat IA.
 *
 * Rôle :
 *  - à l'envoi, POST la demande vers l'endpoint SSE (data-test-ai-*-value) ;
 *  - lit le flux SSE fragment par fragment (fetch + ReadableStream) ;
 *  - pour chaque événement, transmet le Turbo Stream à Turbo via
 *    renderStreamMessage(), ce qui met à jour #ai-output ;
 *  - recopie en direct le contenu de #ai-output dans le transcript, qui est
 *    rendu en Markdown (titres, gras, listes, code…) à l'écran.
 *
 * On utilise fetch() plutôt que l'élément <turbo-stream-source> car ce dernier
 * ne gère que le GET (EventSource), alors qu'on a besoin d'envoyer un corps
 * JSON (la demande) via POST.
 */
export default class extends Controller {
    static targets = [
        'terminal',
        'screen',
        'submit',
        'status',
        'eye',
        'agent',
        'session',
        'temperature',
        'temperatureValue',
        'words',
        'chronoAnalyse',
        'chronoReponse',
        'chronoTotal',
        'chronoSession',
        'model',
        'tokensIn',
        'tokensOut',
        'speed',
    ];
    static values = { streamUrl: String, sessionUrl: String };

    connect() {
        this.abortController = null;
        this.typingTimeout = null;
        // Transcript : liste de blocs { role: 'user' | 'ai', text }.
        // L'écran affiche le rendu Markdown de chaque bloc.
        this.blocks = [];
        // Nombre de caractères de la réponse en cours déjà recopiés depuis
        // #ai-output (le serveur pousse un texte cumulé : on n'ajoute que le delta).
        this.answerLength = 0;
        // Mots de la dernière demande (pour l'animation « délirante » de l'œil).
        this.lastRequest = '';
        this.wordTimer = null;
        this.boundInput = () => this.handleTyping();
        this.boundScroll = () => this.syncScreenScroll();
        // Chronomètres : « session » cumule le temps de traitement actif de la
        // page. Il démarre à zéro, tour pendant une question et se fige à la
        // fin de la réponse (il reprend à la question suivante). Nouvelle page =
        // remise à zéro. Les trois autres (analyse / réponse / total) sont
        // réinitialisés à chaque envoi.
        this.sessionAccum = 0;
        this.sessionSince = null;
        this.questionStart = null;
        this.firstTokenAt = null;
        this.finishedAt = null;
        // Télémétrie du dernier tour (modèle + tokens), reçue via l'événement SSE
        // « usage ». Nulle tant qu'aucune réponse n'a été reçue.
        this.usage = null;
        this.rafId = null;
        // Agent actif (id du rôle) ou null : sa description est injectée en tête
        // de la requête. Aucun agent n'est actif par défaut.
        this.activeAgent = null;
        // Mode session : mémorisation serveur des requêtes/réponses (bouton
        // « Session » de l'en-tête). Désactivé par défaut.
        this.sessionActive = false;
        this.startClock();
        this.setEyeState('idle');
        this.terminalTarget.addEventListener('input', this.boundInput);
        if (this.hasScreenTarget) {
            this.screenTarget.addEventListener('scroll', this.boundScroll);
        }
        this.renderScreen();
        this.renderTemperatureDisplay(this.temperatureTarget?.value ?? '0.7');
    }

    disconnect() {
        this.cancel();
        this.stopWords();
        this.stopClock();
        this.terminalTarget.removeEventListener('input', this.boundInput);
        if (this.hasScreenTarget) {
            this.screenTarget.removeEventListener('scroll', this.boundScroll);
        }
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
     * Sélectionne un agent (bouton exclusif). Presser le bouton déjà actif le
     * désactive (retour à « aucun agent »). Presser un autre bouton active
     * celui-ci et désactive les autres. Seul l'identifiant de l'agent actif est
     * transmis au serveur, qui en déduit la description (prompt système).
     */
    selectAgent(event) {
        const button = event.currentTarget;
        const id = button.dataset.agentId;

        // Toggle : re-presser le bouton actif désactive tout.
        if (this.activeAgent === id) {
            this.activeAgent = null;
        } else {
            this.activeAgent = id;
        }

        this.renderAgents();
    }

    /**
     * Reflète l'état actif sur les boutons (aria-pressed) pour l'accessibilité
     * et le style lumineux.
     */
    renderAgents() {
        for (const button of this.agentTargets) {
            const active = button.dataset.agentId === this.activeAgent;
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        }
    }

    /**
     * Active/désactive le mode session (bouton « Session »). Lorsqu'il est actif,
     * le serveur mémorise les requêtes et les réponses et les renvoie en contexte
     * à chaque nouvel appel.
     */
    toggleSession() {
        this.sessionActive = !this.sessionActive;
        this.renderSession();
    }

    /**
     * Reflète l'état du mode session sur le bouton (aria-pressed + style lumineux).
     */
    renderSession() {
        if (this.hasSessionTarget) {
            this.sessionTarget.setAttribute(
                'aria-pressed',
                this.sessionActive ? 'true' : 'false',
            );
        }
    }

    /**
     * Met à jour l'affichage 7 segments de la température quand on déplace la
     * commande.
     */
    updateTemperature() {
        if (this.hasTemperatureTarget) {
            this.renderTemperatureDisplay(this.temperatureTarget.value);
        }
    }

    /**
     * Dessine la température en afficheur 7 segments (style LED des années 70).
     */
    renderTemperatureDisplay(value) {
        if (!this.hasTemperatureValueTarget) {
            return;
        }
        const text = Number.parseFloat(value).toFixed(1);
        this.renderSegments(this.temperatureValueTarget, text);
        this.temperatureValueTarget.setAttribute(
            'aria-label',
            `Température ${text}`,
        );
    }

    /**
     * Dessine une chaîne de caractères en afficheur 7 segments (chiffres, « : »
     * et « . »). Utilisé pour la température et les chronomètres.
     */
    renderSegments(target, text) {
        let html = '';
        for (const char of text) {
            if (char === '.') {
                html += '<span class="hal-seg__dot"></span>';
                continue;
            }
            if (char === ':') {
                html += '<span class="hal-seg__colon"></span>';
                continue;
            }
            const active = SEGMENTS[char] ?? [];
            const lines = ALL_SEGMENTS.map((segment) => {
                const [x1, y1, x2, y2] = SEGMENT_COORDS[segment];
                const state = active.includes(segment) ? 'on' : 'off';
                return `<line class="${state}" x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" />`;
            }).join('');
            html += `<svg class="hal-seg__digit" viewBox="0 0 10 18" aria-hidden="true">${lines}</svg>`;
        }
        target.innerHTML = html;
    }

    /**
     * Boucle d'animation (requestAnimationFrame) qui rafraîchit les chronomètres.
     * Les temps sont calculés à partir d'horodatages (performance.now), donc le
     * résultat reste exact même si des frames sont sautées.
     */
    startClock() {
        if (this.rafId !== null) {
            return;
        }
        const loop = () => {
            this.renderChronos();
            this.rafId = requestAnimationFrame(loop);
        };
        this.rafId = requestAnimationFrame(loop);
    }

    stopClock() {
        if (this.rafId !== null) {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
        }
    }

    /**
     * Fige le chronomètre « session » : le temps écoulé depuis la reprise est
     * ajouté au cumul et le compteur s'arrête (il reprendra à la question
     * suivante).
     */
    freezeSession() {
        if (this.sessionSince !== null) {
            this.sessionAccum += performance.now() - this.sessionSince;
            this.sessionSince = null;
        }
    }

    /**
     * Met à jour les quatre afficheurs 7 segments des chronomètres.
     */
    renderChronos() {
        const now = performance.now();
        const analyseEnd = this.firstTokenAt ?? this.finishedAt ?? now;
        const reponseEnd = this.finishedAt ?? now;
        const totalEnd = this.finishedAt ?? now;

        const analyse =
            this.questionStart === null ? 0 : analyseEnd - this.questionStart;
        const reponse =
            this.firstTokenAt === null ? 0 : reponseEnd - this.firstTokenAt;
        const total =
            this.questionStart === null ? 0 : totalEnd - this.questionStart;
        const session =
            this.sessionAccum +
            (this.sessionSince === null ? 0 : now - this.sessionSince);

        this.writeChrono(
            this.hasChronoAnalyseTarget ? this.chronoAnalyseTarget : null,
            analyse,
            'Analyse',
        );
        this.writeChrono(
            this.hasChronoReponseTarget ? this.chronoReponseTarget : null,
            reponse,
            'Réponse',
        );
        this.writeChrono(
            this.hasChronoTotalTarget ? this.chronoTotalTarget : null,
            total,
            'Total',
        );
        this.writeChrono(
            this.hasChronoSessionTarget ? this.chronoSessionTarget : null,
            session,
            'Session',
        );
    }

    /**
     * Formate une durée en millisecondes en « HH:MM:SS.CC » (8 chiffres).
     */
    formatChrono(ms) {
        const totalCs = Math.floor(Math.max(0, ms) / 10);
        const cs = totalCs % 100;
        const totalSeconds = Math.floor(totalCs / 100);
        const seconds = totalSeconds % 60;
        const totalMinutes = Math.floor(totalSeconds / 60);
        const minutes = totalMinutes % 60;
        const hours = Math.floor(totalMinutes / 60);
        const pad = (value) => String(value).padStart(2, '0');
        return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}.${pad(cs)}`;
    }

    /**
     * Affiche une durée dans un afficheur 7 segments, sans reconstruire le DOM
     * si la valeur (au centième) n'a pas changé.
     */
    writeChrono(target, ms, label) {
        if (!target) {
            return;
        }
        const text = this.formatChrono(ms);
        if (target.dataset.text === text) {
            return;
        }
        target.dataset.text = text;
        this.renderSegments(target, text);
        target.setAttribute('aria-label', `${label} ${text}`);
    }

    /**
     * Pilote l'état visuel de l'œil de HAL (idle / typing / thinking / error).
     */
    setEyeState(state) {
        if (this.hasEyeTarget) {
            this.eyeTarget.dataset.state = state;
        }
        // Pendant la « réflexion », les mots de la demande flottent dans l'œil.
        if (state === 'thinking') {
            this.startWords();
        } else {
            this.stopWords();
        }
    }

    /**
     * Lance l'animation des mots flottants dans l'œil (état « thinking »).
     */
    startWords() {
        if (!this.hasWordsTarget || this.wordTimer) {
            return;
        }
        this.wordsTarget.innerHTML = '';
        this.spawnWord();
        this.wordTimer = setInterval(() => this.spawnWord(), 240);
    }

    /**
     * Arrête et vide l'animation des mots flottants.
     */
    stopWords() {
        if (this.wordTimer) {
            clearInterval(this.wordTimer);
            this.wordTimer = null;
        }
        if (this.hasWordsTarget) {
            this.wordsTarget.innerHTML = '';
        }
    }

    /**
     * Fait apparaître un mot au hasard dans l'œil, avec une trajectoire et une
     * durée aléatoires (chevauchement, croisement, disparition).
     */
    spawnWord() {
        if (!this.hasWordsTarget) {
            return;
        }
        const words = (this.lastRequest || '').split(/\s+/).filter(Boolean);
        if (0 === words.length) {
            return;
        }

        const word = words[Math.floor(Math.random() * words.length)];
        const span = document.createElement('span');
        span.className = 'hal-word';
        span.textContent = word;

        const angle = Math.random() * Math.PI * 2;
        const distance = 30 + Math.random() * 70;
        span.style.left = `${10 + Math.random() * 80}%`;
        span.style.top = `${10 + Math.random() * 80}%`;
        span.style.setProperty('--dx', `${Math.cos(angle) * distance}px`);
        span.style.setProperty('--dy', `${Math.sin(angle) * distance}px`);
        span.style.setProperty(
            '--rot',
            `${Math.round(Math.random() * 60 - 30)}deg`,
        );
        span.style.setProperty(
            '--dur',
            `${(1.6 + Math.random() * 2).toFixed(2)}s`,
        );
        span.style.setProperty(
            '--size',
            `${(1.5 + Math.random() * 1.5).toFixed(2)}rem`,
        );
        span.style.color =
            Math.random() > 0.5 ? 'var(--hal-red-soft)' : '#ffd0c8';

        span.addEventListener('animationend', () => span.remove());
        this.wordsTarget.appendChild(span);

        // Limite le nombre d'éléments simultanés.
        while (this.wordsTarget.children.length > 36) {
            this.wordsTarget.firstElementChild?.remove();
        }
    }

    /**
     * Envoi de la demande et ouverture du flux SSE.
     */
    async send() {
        const request = this.terminalTarget.value.trim();
        if (!request || this.isStreaming) {
            return;
        }
        this.lastRequest = request;

        // On ajoute la demande au transcript, puis on vide la zone de saisie.
        this.blocks.push({ role: 'user', text: request });
        this.terminalTarget.value = '';
        this.answerLength = 0;
        // Nouvelle réponse : la télémétrie du tour précédent est remise à zéro.
        this.usage = null;
        this.renderUsage();
        // Nouveau bloc pour la réponse de l'IA (rendu en Markdown, coloré ambre).
        this.blocks.push({ role: 'ai', text: '' });
        this.renderScreen();

        const output = document.getElementById('ai-output');
        if (output) {
            output.textContent = '';
        }

        this.setStatus('Génération…');
        this.setEyeState('thinking');
        this.isStreaming = true;
        this.submitTarget.disabled = true;

        // (Ré)initialisation des chronomètres à l'envoi de la question.
        this.questionStart = performance.now();
        this.firstTokenAt = null;
        this.finishedAt = null;
        // « session » reprend (ou démarre) à chaque question.
        if (this.sessionSince === null) {
            this.sessionSince = performance.now();
        }
        this.renderChronos();

        // Température choisie sur le panneau (0.0 → 2.0).
        const temperature = this.hasTemperatureTarget
            ? Number.parseFloat(this.temperatureTarget.value)
            : 0.7;

        // Agent actif : seul son identifiant est transmis au serveur, qui en
        // déduit la description (prompt système). Le transcript conserve la
        // demande brute de l'utilisateur. Le drapeau « session » indique au
        // serveur d'inclure l'historique mémorisé et d'enregistrer ce tour.
        const agent = this.activeAgent;
        const session = this.sessionActive;

        this.abortController = new AbortController();

        try {
            const response = await fetch(this.streamUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                },
                body: JSON.stringify({
                    message: request,
                    agent,
                    session,
                    temperature,
                }),
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
        const dataLines = [];

        for (const line of rawEvent.split('\n')) {
            if (line.startsWith('event:')) {
                event = line.slice(6).trim();
            } else if (line.startsWith('data:')) {
                // Conforme à la spec SSE : on retire « data: » puis un unique
                // espace initial (et non tous les espaces, pour préserver
                // l'indentation du Markdown). Les lignes « data: » multiples
                // sont recombinées avec un retour chariot.
                let value = line.slice(5);
                if (value.startsWith(' ')) {
                    value = value.slice(1);
                }
                dataLines.push(value);
            }
        }

        const data = dataLines.join('\n');

        if (!data) {
            return;
        }

        // Événement de télémétrie : bilan du modèle et des tokens (émis / reçus).
        // On le mémorise et on rafraîchit le pupitre ; le débit sera recalculé à
        // la fin (lorsque la durée de réponse est connue).
        if (event === 'usage') {
            try {
                this.usage = JSON.parse(data);
            } catch {
                this.usage = null;
            }
            this.renderUsage();
            return;
        }

        // Premier fragment de texte reçu : le temps d'« analyse » (envoi → 1er
        // token) s'arrête et le temps de « réponse » (1er token → fin) démarre.
        if (event === 'message' && this.firstTokenAt === null) {
            this.firstTokenAt = performance.now();
        }

        // On laisse Turbo appliquer le Turbo Stream (met à jour #ai-output).
        // renderStreamMessage est asynchrone (il attend un repaint) : on attend
        // donc le prochain frame avant de recopier le texte dans le transcript.
        renderStreamMessage(data);
        await new Promise((resolve) => requestAnimationFrame(resolve));

        // On recopie la réponse cumulée dans le transcript (rendu Markdown).
        this.renderAnswer();

        if (event === 'done') {
            // Fin de la réponse : on fige « réponse » et « total ». Si aucun
            // token n'a été reçu, « analyse » s'arrête aussi à cet instant.
            this.finishedAt = performance.now();
            if (this.firstTokenAt === null) {
                this.firstTokenAt = this.finishedAt;
            }
            this.freezeSession();
            this.renderChronos();
            this.renderUsage();
            this.setStatus('Terminé.');
            this.setEyeState('idle');
            this.placeCaretAtEnd();
        }
    }

    /**
     * En fin de streaming : place le curseur dans la zone de saisie et défile
     * le transcript en bas.
     */
    placeCaretAtEnd() {
        this.terminalTarget.focus();
        this.scrollScreenToBottom();
    }

    /**
     * Recopie la réponse cumulée (#ai-output) dans le dernier bloc « ai » en
     * n'ajoutant que le nouveau delta (le serveur renvoie du texte cumulé).
     * L'opération est idempotente : un appel supplémentaire avec le même
     * contenu n'ajoute rien, ce qui évite tout doublon.
     */
    renderAnswer() {
        const output = document.getElementById('ai-output');
        if (!output) {
            return;
        }
        const answer = output.textContent;
        if (answer.length <= this.answerLength) {
            return;
        }
        this.answerLength = answer.length;
        const block = this.blocks.findLast((item) => item.role === 'ai');
        if (block) {
            block.text = answer;
            this.renderScreen();
        }
    }

    /**
     * Reconstruit le transcript à l'écran : chaque demande est affichée en
     * texte brut (vert), chaque réponse est rendue en Markdown (ambre).
     */
    renderScreen() {
        if (!this.hasScreenTarget) {
            return;
        }
        this.screenTarget.innerHTML = this.blocks
            .map((block) => {
                if (block.role === 'user') {
                    return `<div class="hal-msg hal-msg--user">${escapeHtml(block.text)}</div>`;
                }
                return `<div class="hal-msg hal-msg--ai">${marked.parse(block.text)}</div>`;
            })
            .join('');
        this.scrollScreenToBottom();
    }

    /**
     * Fait défiler le transcript vers le bas (dernière ligne visible).
     */
    scrollScreenToBottom() {
        if (this.hasScreenTarget) {
            this.screenTarget.scrollTop = this.screenTarget.scrollHeight;
        }
    }

    /**
     * Synchronise le défilement du miroir avec celui du textarea.
     */
    syncScreenScroll() {
        // Le transcript est désormais l'unique zone défilante : rien à
        // synchroniser. Méthode conservée pour le listener « scroll ».
    }

    /**
     * Vide le terminal et la zone de sortie (seul moyen d'effacer le texte).
     * La session serveur est également effacée (bouton « Effacer »).
     */
    reset() {
        this.cancel();
        this.clearSession();
        this.blocks = [];
        this.terminalTarget.value = '';
        this.answerLength = 0;
        this.usage = null;
        this.renderUsage();
        // Les chronomètres par question sont remis à zéro ; « session » continue.
        this.questionStart = null;
        this.firstTokenAt = null;
        this.finishedAt = null;
        this.renderChronos();
        const output = document.getElementById('ai-output');
        if (output) {
            output.textContent = '';
        }
        this.setStatus('EN ATTENTE');
        this.setEyeState('idle');
        this.renderScreen();
    }

    /**
     * Demande au serveur d'effacer l'historique de la session. Appel « fire and
     * forget » : le reset du terminal est immédiat, sans attendre la réponse.
     */
    clearSession() {
        if (!this.hasSessionUrlValue) {
            return;
        }
        fetch(this.sessionUrlValue, {
            method: 'POST',
            headers: { Accept: 'application/json' },
        }).catch(() => {
            // Une erreur d'effacement ne doit pas bloquer le reset local.
        });
    }

    /**
     * Annule la requête en cours, s'il y en a une.
     */
    cancel() {
        if (this.abortController) {
            this.abortController.abort();
            this.abortController = null;
        }
        // Annulation en cours de route : on fige les chronomètres par question
        // pour qu'ils ne continuent pas à défiler indéfiniment.
        if (this.questionStart !== null && this.finishedAt === null) {
            this.finishedAt = performance.now();
            if (this.firstTokenAt === null) {
                this.firstTokenAt = this.finishedAt;
            }
        }
        this.freezeSession();
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

    /**
     * Met à jour le pupitre de télémétrie (modèle, tokens émis / reçus, débit).
     * Le débit est le nombre de tokens reçus par seconde écoulée entre le premier
     * et le dernier token (la durée de « réponse »). En l'absence de données, on
     * affiche des valeurs neutres.
     */
    renderUsage() {
        const usage = this.usage ?? {};
        const prompt = Number(usage.prompt ?? 0);
        const completion = Number(usage.completion ?? 0);

        let speed = 0;
        if (this.firstTokenAt !== null && this.finishedAt !== null) {
            const seconds = (this.finishedAt - this.firstTokenAt) / 1000;
            if (seconds > 0) {
                speed = completion / seconds;
            }
        }

        this.writeValue(
            this.hasModelTarget ? this.modelTarget : null,
            usage.model ?? '—',
        );
        this.writeValue(
            this.hasTokensInTarget ? this.tokensInTarget : null,
            String(prompt),
        );
        this.writeValue(
            this.hasTokensOutTarget ? this.tokensOutTarget : null,
            String(completion),
        );
        this.writeValue(
            this.hasSpeedTarget ? this.speedTarget : null,
            speed.toFixed(1),
        );
    }

    /**
     * Affiche une valeur en la faisant « clignoter » (animation HAL) lorsqu'elle
     * change, sans reconstruire le DOM inutilement.
     */
    writeValue(target, text) {
        if (!target || target.textContent === text) {
            return;
        }
        target.textContent = text;
        target.classList.remove('is-updating');
        // Force le redémarrage de l'animation CSS.
        void target.offsetWidth;
        target.classList.add('is-updating');
    }
}
