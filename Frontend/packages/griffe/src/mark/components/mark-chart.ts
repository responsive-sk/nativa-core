import { LitElement, html, css, PropertyValues } from 'lit';
import { customElement, property, query } from 'lit/decorators.js';
import { Chart, registerables, ChartConfiguration, ChartType } from 'chart.js';

Chart.register(...registerables);

@customElement('mark-chart')
export class MarkChart extends LitElement {
    @property({ type: String }) type: ChartType = 'line';
    @property({ type: Object }) data: any = {};
    @property({ type: Object }) options: any = {};
    @property({ type: String }) height = '300px';

    @query('canvas') canvas!: HTMLCanvasElement;

    private chart: Chart | null = null;

    static styles = css`
        :host {
            display: block;
            width: 100%;
            position: relative;
        }
        .chart-container {
            position: relative;
            width: 100%;
        }
    `;

    firstUpdated() {
        this.initChart();
    }

    updated(changedProperties: PropertyValues) {
        if (this.chart) {
            if (changedProperties.has('data')) {
                this.chart.data = this.data;
                this.chart.update();
            }
            if (changedProperties.has('type')) {
                this.chart.destroy();
                this.initChart();
            }
        }
    }

    private initChart() {
        const ctx = this.canvas.getContext('2d');
        if (!ctx) return;

        // Default admin theme options
        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        color: '#a1a5b7',
                        font: { family: 'Inter' }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { color: '#a1a5b7', font: { family: 'Inter' } }
                },
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { color: '#a1a5b7', font: { family: 'Inter' } }
                }
            }
        };

        const config: ChartConfiguration = {
            type: this.type,
            data: this.data,
            options: { ...defaultOptions, ...this.options }
        };

        this.chart = new Chart(ctx, config);
    }

    render() {
        return html`
            <div class="chart-container" style="height: ${this.height}">
                <canvas></canvas>
            </div>
        `;
    }
}
