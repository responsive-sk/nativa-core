import { LitElement, html, css } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';

export type ToastType = 'success' | 'error' | 'info' | 'warning';

export interface ToastOptions {
    message: string;
    type?: ToastType;
    duration?: number;
}

@customElement('boson-toast')
export class BosonToast extends LitElement {
    @property({ type: String }) type: ToastType = 'info';
    @property({ type: Number }) duration = 3000;
    @state() private _visible = false;
    @property({ type: String }) message = '';

    private _timer: any;

    static styles = css`
    :host {
      display: block;
      width: 100%;
      pointer-events: auto;
      font-family: 'Inter', sans-serif;
    }

    .toast {
      background: var(--surface-1, #ffffff);
      color: var(--text-1, #111827);
      padding: 1rem 1.25rem;
      border-radius: 8px;
      box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 300px;
      max-width: 400px;
      border: 1px solid var(--border-color, #e5e7eb);
      position: relative;
      overflow: hidden;
      
      opacity: 0;
      transform: translateY(20px);
      transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    
    /* Dark mode support if CSS vars are set */
    @media (prefers-color-scheme: dark) {
        .toast {
            background: #1f2937;
            color: #f9fafb;
            border-color: #374151;
        }
    }

    .toast::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 4px;
        background: var(--toast-color, #3b82f6);
    }

    .toast.visible {
      opacity: 1;
      transform: translateY(0);
    }

    .toast-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }

    .toast-content {
        flex: 1;
        font-size: 0.95rem;
        font-weight: 500;
        line-height: 1.4;
    }

    .close-btn {
        background: transparent;
        border: none;
        color: inherit;
        cursor: pointer;
        padding: 4px;
        display: flex;
        align-items: center;
        font-size: 1.2rem;
        opacity: 0.4;
        transition: opacity 0.2s;
    }
    .close-btn:hover {
        opacity: 1;
    }

    /* Types */
    .type-success { --toast-color: #10b981; }
    .type-error { --toast-color: #ef4444; }
    .type-warning { --toast-color: #f59e0b; }
    .type-info { --toast-color: #3b82f6; }
  `;

    connectedCallback() {
        super.connectedCallback();
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
            this._timer = null;
        }
    }

    close() {
        if (this._timer) {
            clearTimeout(this._timer);
            this._timer = null;
        }
        this._visible = false;
        setTimeout(() => {
            this.dispatchEvent(new CustomEvent('closed', { bubbles: true, composed: true }));
            this.remove();
        }, 300);
    }

    render() {
        let icon = '';
        switch (this.type) {
            case 'success': icon = '🎉'; break;
            case 'error': icon = '🚨'; break;
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

// Global Notification Manager for Public Site
export class BosonNotificationManager {
    static init() {
        if (!document.getElementById('boson-toast-container')) {
            const container = document.createElement('div');
            container.id = 'boson-toast-container';
            Object.assign(container.style, {
                position: 'fixed',
                bottom: '24px', // Public toast usually bottom-right or bottom-center
                right: '24px',
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
        const container = document.getElementById('boson-toast-container');
        if (container) {
            const toast = document.createElement('boson-toast') as BosonToast;
            toast.message = options.message;
            toast.type = options.type || 'info';
            toast.duration = options.duration || 4000;
            container.appendChild(toast);
        }
    }

    static success(msg: string) { this.show({ message: msg, type: 'success' }); }
    static error(msg: string) { this.show({ message: msg, type: 'error' }); }
    static info(msg: string) { this.show({ message: msg, type: 'info' }); }
}

// Expose globally
(window as any).BosonNotify = BosonNotificationManager;
