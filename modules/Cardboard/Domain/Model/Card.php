<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Domain\Model;

use Modules\Cardboard\Domain\Contracts\ChartContract;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;

/**
 * Card — immutable dashboard stat card value object.
 *
 * Every dashboard element is a Card: a number, a label, optional
 * context (subtitle), optional visual data (chart), and optional
 * action links. The with*() methods return new instances (immutable).
 *
 * Usage:
 *   Card::make('users', 'Users', 42)
 *       ->withActions(
 *           CardLink::make('List', '/mark/users'),
 *           CardLink::make('New', '/mark/users/create'),
 *       )
 *       ->withSubtitle('3 active today')
 *       ->withTrend(Trend::make(12, 8))
 *       ->withChart(new Sparkline([10, 25, 18, 32]))
 *       ->withPriority(100)
 */
final readonly class Card
{
    /**
     * @param Trend|null     $trend    Optional period-over-period change badge (rendered as trusted markup).
     * @param list<CardLink> $actions  Action links rendered as pills below the card.
     * @param int            $priority Higher values render first.
     */
    public function __construct(
        public string $id,
        public string $label,
        public int $value,
        public string $color = 'primary',
        public ?string $subtitle = null,
        public ?Trend $trend = null,
        public array $actions = [],
        public ?ChartContract $chart = null,
        public int $priority = 0,
    ) {
    }

    /**
     * Named constructor — the recommended entry point.
     */
    public static function make(string $id, string $label, int $value): self
    {
        return new self(id: $id, label: $label, value: $value);
    }
    public function withSubtitle(string $subtitle): self
    {
        return new self(
            id: $this->id,
            label: $this->label,
            value: $this->value,
            color: $this->color,
            subtitle: $subtitle,
            trend: $this->trend,
            actions: $this->actions,
            chart: $this->chart,
            priority: $this->priority,
        );
    }

    /**
     * Attach a period-over-period change badge. The badge is rendered as
     * markup from the typed Trend value object, so the plain-text subtitle
     * stays escaped — no HTML ever enters the subtitle string.
     */
    public function withTrend(Trend $trend): self
    {
        return new self(
            id: $this->id,
            label: $this->label,
            value: $this->value,
            color: $this->color,
            subtitle: $this->subtitle,
            trend: $trend,
            actions: $this->actions,
            chart: $this->chart,
            priority: $this->priority,
        );
    }

    /**
     * @param CardLink ...$links Action links to add.
     */
    public function withActions(CardLink ...$links): self
    {
        /** @var list<CardLink> $merged */
        $merged = array_merge($this->actions, $links);

        return new self(
            id: $this->id,
            label: $this->label,
            value: $this->value,
            color: $this->color,
            subtitle: $this->subtitle,
            trend: $this->trend,
            actions: $merged,
            chart: $this->chart,
            priority: $this->priority,
        );
    }

    public function withChart(?ChartContract $chart): self
    {
        return new self(
            id: $this->id,
            label: $this->label,
            value: $this->value,
            color: $this->color,
            subtitle: $this->subtitle,
            trend: $this->trend,
            actions: $this->actions,
            chart: $chart,
            priority: $this->priority,
        );
    }

    public function withPriority(int $priority): self
    {
        return new self(
            id: $this->id,
            label: $this->label,
            value: $this->value,
            color: $this->color,
            subtitle: $this->subtitle,
            trend: $this->trend,
            actions: $this->actions,
            chart: $this->chart,
            priority: $priority,
        );
    }

    /**
     * Render the card as an HTML string.
     *
     * Structure (analytics card): <article> with a <header> (label), a
     * <div class="m-stat__body"> (value + subtitle on the left, optional
     * chart on the full right side), and a <footer> with the action links
     * when the card has any. All user-facing text stays escaped — the only
     * markup allowed through is the typed Trend badge.
     */
    public function render(): string
    {
        $modifier = null !== $this->chart ? ' m-stat--with-chart' : '';

        return '<article class="m-stat' . $modifier . '">'
            . $this->renderHead()
            . $this->renderBody()
            . $this->renderFoot()
            . '</article>';
    }

    /**
     * Card header — the escaped label as a heading with the trend badge
     * (typed markup) pinned to the right edge, away from the body text.
     */
    private function renderHead(): string
    {
        return '<header class="m-stat__head">'
            . '<h3 class="m-stat__label">' . AdminLayout::e($this->label) . '</h3>'
            . (null !== $this->trend ? $this->trend->render() : '')
            . '</header>';
    }

    /**
     * Card body — left stat column (value + subtitle) and, when a chart is
     * present, the chart panel filling the remaining width/height.
     */
    private function renderBody(): string
    {
        $subtitleHtml = $this->renderSubtitle();
        $main = '<div class="m-stat__main">'
            . '<div class="m-stat__value">' . $this->value . '</div>'
            . $subtitleHtml
            . '</div>';

        if (null === $this->chart) {
            return '<div class="m-stat__body">' . $main . '</div>';
        }

        $chartHtml = $this->chart->render();
        $chart = '' !== $chartHtml ? '<div class="m-stat__chart">' . $chartHtml . '</div>' : '';

        return '<div class="m-stat__body">' . $main . $chart . '</div>';
    }

    /**
     * Subtitle line — plain escaped text only (e.g. "in 7 days — 4 SK · 4 EN");
     * the trend badge lives in the header. Never embeds HTML into the text.
     */
    private function renderSubtitle(): string
    {
        $subtitleText = (string) ($this->subtitle ?? '');
        if ('' === $subtitleText) {
            return '';
        }

        return '<div class="m-stat-subtitle">' . AdminLayout::e($subtitleText) . '</div>';
    }

    /**
     * Card footer with the action links; omitted when the card has none.
     */
    private function renderFoot(): string
    {
        $actionsHtml = '';
        foreach ($this->actions as $link) {
            $actionsHtml .= '<a class="m-stat-link" href="'
                . AdminLayout::e($link->href) . '">'
                . AdminLayout::e($link->label) . '</a>';
        }

        if ('' === $actionsHtml) {
            return '';
        }

        return '<footer class="m-stat__foot">' . $actionsHtml . '</footer>';
    }
}
