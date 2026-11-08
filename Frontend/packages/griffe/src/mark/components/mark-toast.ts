import { LitElement, html, css } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';

export type ToastType = 'success' | 'error' | 'info' | 'warning';

export interface ToastOptions {
    message: string;
    type?: ToastType;
    duration?: number;
}

@customElement('mark-toast')
export class MarkToast extends LitElement {
    @property({ type: String }) type: ToastType = 'info';
    @property({ type: Number }) duration = 3000;
    @state() private _visible = false;
    @property({ type: String }) message = '';

    private _timer: ReturnType<typeof setTimeout> | undefined;

    static styles = css`
    :host {
      display: block;
      width: 100%;
      pointer-events: auto;
    }

    .toast {
      background: var(--admin-card-bg, #1e1e2d);
      color: var(--admin-text-primary, #ffffff);
      padding: 1rem 1.5rem;
      border-radius: 0.6rem;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 300px;
      max-width: 400px;
      border-left: 4px solid var(--toast-color, #009ef7);
      
      opacity: 0;
      transform: translateX(50px);
      transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
      pointer-events: auto;
    }

    .toast.visible {
      opacity: 1;
      transform: translateX(0);
    }

    .toast-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
    }

    .toast-content {
        flex: 1;
        font-size: 0.95rem;
        font-weight: 500;
    }

    .close-btn {
        background: transparent;
        border: none;
        color: var(--admin-text-secondary, #a1a5b7);
        cursor: pointer;
        padding: 0;
        display: flex;
        align-items: center;
        font-size: 1.2rem;
        opacity: 0.7;
    }
    .close-btn:hover {
        opacity: 1;
    }

    /* Types */
    .type-success { --toast-color: var(--admin-success, #50cd89); }
    .type-error { --toast-color: var(--admin-danger, #f1416c); }
    .type-warning { --toast-color: #ffc700; }
    .type-info { --toast-color: #009ef7; }
  `;

    connectedCallback() {
        super.connectedCallback();
        // Auto show on connect if message exists
        requestAnimationFrame(() => {
            this._visible = true;
        });

        if (this.duration > 0) {
            this._timer = setTimeout(() => {
                this.close();
            }, this.duration);
        }
    }

    disconnectedCallback() {
        super.disconnectedCallback();

        if (this._timer) {
            clearTimeout(this._timer);
            this._timer = undefined;
        }
    }

    close() {
        this._visible = false;
        setTimeout(() => {
            this.dispatchEvent(new CustomEvent('closed', { bubbles: true, composed: true }));
            this.remove();
        }, 300); // Wait for animation
    }

    render() {
        let icon = '';
        switch (this.type) {
            case 'success': icon = '✅'; break;
            case 'error': icon = '❌'; break;
            case 'warning': icon = '⚠️'; break;
            case 'info': icon = 'ℹ️'; break;
        }

        return html`
      <div class="toast type-${this.type} ${this._visible ? 'visible' : ''}">
        <div class="toast-icon">${icon}</div>
        <div class="toast-content">${this.message}</div>
        <button class="close-btn" @click="${this.close}">×</button>
      </div>
    `;
    }
}

// Global Notification Manager for Mark
export class MarkNotificationManager {
    static init() {
        if (!document.getElementById('mark-toast-container')) {
            const container = document.createElement('div');
            container.id = 'mark-toast-container';
            Object.assign(container.style, {
                position: 'fixed',
                top: '20px',
                right: '20px',
                zIndex: '9999',
                display: 'flex',
                flexDirection: 'column',
                gap: '10px',
                pointerEvents: 'none'
            });
            document.body.appendChild(container);
        }
    }

    static show(options: ToastOptions) {
        this.init();
        const container = document.getElementById('mark-toast-container');
        if (container) {
            const toast = document.createElement('mark-toast') as MarkToast;
            toast.message = options.message;
            toast.type = options.type || 'info';
            toast.duration = options.duration || 3000;

            // Fix positioning style in the web component to not overlap manually
            // Actually the component has fixed position styles :host. 
            // We should override :host position to static if inside a container.
            // But Shadow DOM styles are hard to override. 
            // Better to update component styles to be relative if we use a container.
            // I will update styles above to remove 'fixed' from :host and let container handle it.

            container.appendChild(toast);
        }
    }

    static success(msg: string) { this.show({ message: msg, type: 'success' }); }
    static error(msg: string) { this.show({ message: msg, type: 'error' }); }
}

// Expose globally
(window as any).MarkNotify = MarkNotificationManager;
