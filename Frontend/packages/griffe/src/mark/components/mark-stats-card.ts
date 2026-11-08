import { LitElement, html, css } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import './mark-card';

@customElement('mark-stats-card')
export class MarkStatsCard extends LitElement {
    @property({ type: String }) title = '';
    @property({ type: String }) value = '0';
    @property({ type: String }) trend = '';
    @property({ type: String }) icon = '📊';
    @property({ type: String }) iconBg = '#e1f0ff';

    static styles = css`
    :host {
      display: block;
    }

    .stat-header {
      display: flex;
      align-items: center;
      margin-bottom: 1rem;
    }

    .icon-box {
      width: 40px;
      height: 40px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 1rem;
      font-size: 1.25rem;
      /* default light mode bg, override inline for specific colors if needed, 
         but ideally we map variants */
    }

    .stat-title {
      margin: 0;
      font-size: 0.9rem;
      color: var(--admin-text-secondary, #a1a5b7);
      font-weight: 600;
    }

    .stat-value {
      font-size: 2rem;
      font-weight: 700;
      color: var(--admin-text-primary, #ffffff);
    }
    
    .stat-trend {
        font-size: 0.85rem;
        margin-top: 0.5rem;
    }
  `;

    render() {
        return html`
      <mark-card>
        <div class="stat-header">
            <div class="icon-box" style="background: ${this.iconBg}">
                ${this.icon}
            </div>
            <div>
                <h3 class="stat-title">${this.title}</h3>
            </div>
        </div>
        <div class="stat-value">${this.value}</div>
        <div class="stat-trend" style="color: ${this.trend.startsWith('+') ? '#50cd89' : '#f1416c'}">
            ${this.trend}
        </div>
      </mark-card>
    `;
    }
}
