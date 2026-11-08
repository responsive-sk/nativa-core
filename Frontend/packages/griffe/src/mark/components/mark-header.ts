import { LitElement, html, css } from 'lit';
import { customElement, property } from 'lit/decorators.js';

@customElement('mark-header')
export class MarkHeader extends LitElement {
    @property({ type: String }) title = 'Dashboard';
    @property({ type: String }) user = 'Mark User';
    @property({ type: String }) role = 'Manager';

    static styles = css`
    :host {
      display: flex;
      height: 100%;
      align-items: center;
      justify-content: space-between;
      padding: 0 2rem;
      background: var(--admin-card-bg, #1e1e2d);
      color: var(--admin-text-primary, #ffffff);
    }

    h1 {
      margin: 0;
      font-size: 1.2rem;
      font-weight: 700;
    }

    .user-profile {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .user-info {
        text-align: right; 
        margin-right: 0.5rem;
    }

    .user-name {
        font-weight: 600; 
        font-size: 0.9rem;
        color: var(--admin-text-primary, #fff);
    }

    .user-role {
        font-size: 0.8rem; 
        color: var(--admin-text-secondary, #6c757d);
    }

    .user-avatar {
      width: 40px;
      height: 40px;
      background: #323248;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      color: #fff;
    }
  `;

    render() {
        return html`
      <div class="header-breadcrumbs">
        <h1>${this.title}</h1>
      </div>

      <div class="user-profile">
        <div class="user-info">
            <div class="user-name">${this.user}</div>
            <div class="user-role">${this.role}</div>
        </div>
        <div class="user-avatar">
            ${this.user.substring(0, 2).toUpperCase()}
        </div>
      </div>
    `;
    }
}
