/**
 * app.ts — the full resp-front component aggregator, OPT-IN.
 *
 * NOT imported by the shipped `griffe` entry. It used to be, and the cost was
 * the whole component library in a bundle every visitor downloads on every
 * page — while the PHP theme renders exactly two custom elements
 * (`hero-layout`, `horizontal-scroll-hero`). Import this file from
 * `src/griffe.ts` only when a surface starts rendering more of these elements;
 * each import here is bytes on the critical path of every page that loads the
 * bundle (it is deferred, but it is still downloaded).
 *
 * It also no longer starts PJAX, for the same reason it no longer logs: the
 * theme renders no `[data-container]`, so `initPJAX()` set a global flag and
 * returned, and the console carried four debug lines on every page load.
 */

// UI kit
import './components/ui/breadcrumbs'
import './components/ui/button'
import './components/ui/footer'
import './components/ui/header'
import './components/ui/horizontal-accordion'
import './components/ui/logos/logo'
import './components/ui/mobile-header-menu'
import './components/ui/page-title'
import './components/ui/search-input'
import './components/ui/slider'
import './components/ui/subtitle'

// Layouts
import './layout/default'
import './layout/hero-layout'
import './layout/landing'
import './layout/search'

// Sections
import './components/fullscreen-hero'
import './components/sections/article-detail-section'
import './components/sections/blog-list-section'
import './components/sections/call-to-action-section'
import './components/sections/gallery-section'
import './components/sections/hero-section'
import './components/sections/hero-slider-section'
import './components/sections/horizontal-scroll-hero'
import './components/sections/how-it-works-section'
import './components/sections/mobile-development-section'
import './components/sections/nativeness-section'
import './components/sections/right-choice-section'
import './components/sections/segment-section'
import './components/sections/solves-section'
import './components/sections/testimonials-section'

import './app.css'
