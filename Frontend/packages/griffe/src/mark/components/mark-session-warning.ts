import { LitElement, html, css } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';

@customElement('mark-session-warning')
export class MarkSessionWarning extends LitElement {
    @property({ type: Number }) timeout = 1800; // Total seconds (30m)
    @property({ type: Number }) warning = 300;   // Warning seconds (5m)
    @property({ type: String }) ignoreRoutes = '/login,/register';
    @property({ type: String }) pingUrl = '/api/session/ping';
    @property({ type: String }) logoutUrl = '/logout';

    @state() private _timeLeft = 0;
    @state() private _showWarning = false;

    private _counter: any;
    private _lastActivity: number = Date.now();

    static styles = css`
        :host {
            display: block;
            font-family: 'Inter', sans-serif;
        }
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }
        .modal-overlay.visible {
            opacity: 1;
            pointer-events: auto;
        }
        .modal-card {
            background: #1e1e2d;
            border: 1px solid #2b2b40;
            border-radius: 12px;
            padding: 2rem;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            text-align: center;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }
        .modal-overlay.visible .modal-card {
            transform: translateY(0);
        }
        h2 {
            color: #ffffff;
            margin: 0 0 1rem 0;
            font-size: 1.5rem;
        }
        p {
            color: #a1a5b7;
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }
        .timer {
            font-size: 2.5rem;
            font-weight: 700;
            color: #f1416c;
            margin-bottom: 1.5rem;
            font-variant-numeric: tabular-nums;
        }
        .actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        button {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: filter 0.2s;
        }
        .btn-stay {
            background: #009ef7;
            color: white;
        }
        .btn-logout {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
        }
        button:hover {
            filter: brightness(1.1);
        }
    `;

    connectedCallback() {
        super.connectedCallback();

        // Don't run on ignore routes
        const path = window.location.pathname;
        if (this.ignoreRoutes.split(',').some(r => path.startsWith(r))) {
            return;
        }

        this.startTracking();
    }

    startTracking() {
        ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'].forEach(evt => {
            window.addEventListener(evt, () => this.resetTimer(), { passive: true });
        });

        this.resetTimer();

        // Check every second for warning locally
        this._counter = setInterval(() => {
            const now = Date.now();
            const elapsed = (now - this._lastActivity) / 1000;
            const remaining = this.timeout - elapsed;

            this._timeLeft = Math.max(0, Math.floor(remaining));

            if (remaining <= 0) {
                // Timeout reached
                window.location.href = this.logoutUrl + '?reason=timeout';
                clearInterval(this._counter);
            } else if (remaining <= this.warning) {
                // Show warning
                if (!this._showWarning) {
                    this._showWarning = true;
                }
            } else {
                // Hide warning if user became active (though resetTimer handles this usually)
                if (this._showWarning) {
                    this._showWarning = false;
                }
            }
        }, 1000);
    }

    resetTimer() {
        this._lastActivity = Date.now();
        // If warning was shown, check if we need to ping
        if (this._showWarning) {
            this._showWarning = false;
            this.ping();
        }
    }

    async ping() {
        try {
            await fetch(this.pingUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            this._lastActivity = Date.now();
            this._showWarning = false;
        } catch (e) {
            console.error('Session ping failed', e);
        }
    }

    render() {
        const minutes = Math.floor(this._timeLeft / 60);
        const seconds = this._timeLeft % 60;
        const timeString = `${minutes}:${seconds.toString().padStart(2, '0')}`;

        return html`
            <div class="modal-overlay ${this._showWarning ? 'visible' : ''}">
                <div class="modal-card">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">⏳</div>
                    <h2>Session Timeout Warning</h2>
                    <p>Your session is about to expire due to inactivity.<br>Please choose an action to continue.</p>
                    
                    <div class="timer">${timeString}</div>
                    
                    <div class="actions">
                        <button class="btn-logout" @click="${() => window.location.href = this.logoutUrl}">Logout</button>
                        <button class="btn-stay" @click="${() => this.ping()}">Stay Logged In</button>
                    </div>
                </div>
            </div>
        `;
    }
}
