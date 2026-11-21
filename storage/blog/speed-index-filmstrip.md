---
title: "Speed Index 2,3 s pri FCP 0,9 s. Rozhodne filmstrip, nie metriky"
date: "2026-11-21"
tags: ["performance", "lighthouse", "speed-index", "diagnostika"]
excerpt: "Štyri zelené metriky a jedna, ktorá nesedí. Ako z JSON reportu vytiahnuť filmstrip a nájsť frame, ktorý Speed Index nafúkol."
author: "responsive.sk"
category: "Performance"
published: true
---

Toto je report, ktorý človek dostane a nemá z neho čo robiť:

```text
First Contentful Paint   0.9 s
Largest Contentful Paint 0.9 s
Total Blocking Time      0 ms
Cumulative Layout Shift  0
Speed Index              2.3 s
```

Štyri metriky sú zelené, stránka je podľa nich hotová v 0,9 s. A predsa je tam tretina sekundy… teda sekunda a pol, ktorá sa nikde inde neukáže. Kde presne? To metriky nepovedia. Filmstrip povie.

## Čo Speed Index naozaj merí

Speed Index nie je čas jedného prvku. Je to plocha nad krivkou vizuálnej kompletnosti: prehliadač robí snímky stránky v pravidelných intervaloch (v Lighthouse po ~375 ms), z každej sa spočíta, koľko percent obsahu už je vykreslených, a čím dlhšie sa tá kompletnosť plazí k 100 %, tým vyššie číslo.

Z toho vyplýva dôležitá vec: **SI je len tak presný, ako dobré sú snímky, z ktorých sa počíta.** FCP aj LCP odpovedajú na otázku „kedy sa objavil prvok". SI odpovedá na „kedy bola stránka hotová" — a to je otázka o pixeloch, nie o eventoch. Preto sa nedá overiť inak než pozretím na snímky.

## Náš prípad: 3,9 s → 2,3 s → 0,9 s

Na responsive.sk sme mali rovnaký podpis: FCP a LCP okolo 0,9 s, TBT nula, CLS nula, ale Speed Index vysoko. Prvý krok nebol optimalizácia, ale dôkaz, že to nie je meranie.

Z JSON reportu z Lighthouse sa dajú snímky vytiahnuť priamo — sú v audite `screenshot-thumbnails` ako data URI:

```bash
npx lighthouse https://example.com/ \
  --output=json --output-path=/tmp/lh.json \
  --only-categories=performance --quiet
```

```python
import base64, json, os
d = json.load(open('/tmp/lh.json'))
os.makedirs('/tmp/frames', exist_ok=True)
for i, it in enumerate(d['audits']['screenshot-thumbnails']['details']['items']):
    raw = it['data'].split(',', 1)[-1]
    open(f"/tmp/frames/f{i:02d}_{int(it['timing'])}ms.png", 'wb').write(base64.b64decode(raw))
```

Potom stačí porovnať posledné snímky. Ak sú pixelovo rovnaké, stránka bola hotová skôr, než hovorí SI — a rozdiel je v meraní, nie v stránke:

```python
from PIL import Image, ImageChops
a = Image.open('/tmp/frames/f02_1125ms.png').convert('RGB')
b = Image.open('/tmp/frames/f04_1875ms.png').convert('RGB')
print(ImageChops.difference(a, b).getbbox())   # None = žiadna zmena
```

U nás vyšlo `None`. Snímka z 1,1 s a snímka z 1,9 s boli identické, takže posledný viditeľný frame prišiel v 1,1 s. Namerané hodnoty pri rôznych profiloch:

| profil | FCP | LCP | TBT | CLS | SI |
| --- | --- | --- | --- | --- | --- |
| Lighthouse default (simulovaný) | 0,8 s | 1,2 s | 40 ms | 0 | 0,9 s |
| DevTools 150 ms / 1,6 Mbit / 4× CPU | 0,9 s | 0,9 s | 0 ms | 0 | 1,0 s |
| DevTools 300 ms / 1,0 Mbit / 6× CPU | 1,0 s | 1,0 s | 0 ms | 0 | 1,0 s |
| woff2 zablokované (kontrolný test) | 0,9 s | 0,9 s | 0 ms | 0 | 0,9 s |

RIADOK. Ten posledný riadok je dôležitý: zablokovanie dvoch fontov (24 KB a 50 KB) zmenilo SI len o 0,1 s. Keby bol na vine font swap po FCP, rozdiel by bol oveľa väčší. Podozrenie padlo — a to je výsledok, ktorý sa dá obhájiť, lebo je zmeraný.

## Ako priradiť vinu konkrétnemu assetu

Dve pomôcky Lighthouse, ktoré ušetria hodiny hádania:

- `--throttling-method=devtools` spustí reálne (aplikované) spomaľovanie namiesto simulovaného. Simulované meria „čo by bolo, keby", aplikované meria skutočnú sieť a CPU — a ich rozpor je sám o sebe diagnóza.
- `--blocked-url-patterns='*/fonts/*'` zablokuje vybrané requesty. Keď sa číslo pohne, vinník je nájdený; keď nie, hypotéza je mŕtva.

Takto sa z „myslím, že to budú fonty" stane „fonty to nie sú, merané".

## Tri príčiny, ktoré filmstrip odhalí a metriky nie

1. **Neskorá výmena fontu.** Text sa vykreslí fallbackom (FCP aj LCP sú nízko), potom dorazí woff2 a prekreslí celú stránku. LCP sa nezmení, SI áno. Pomáha subset, preload a `size-adjust` fallback, ktorý drží metriku textu.
2. **Neskorý obrázok alebo animácia.** Hero s `loading="lazy"`, carousel, ktorý sa rozbehne až po hydratácii, alebo fade-in celého obsahu. V metrickej tabuľke nič, vo filmstripe jasný skok.
3. **Meranie, nie stránka.** Aplikované spomaľovanie na pomalom pripojení, beh z inej lokality, otvorené DevTools s iným profilom. Rovnaká stránka, iné číslo — a najhoršie je, že sa to nedá vyvrátiť bez snímok.

## Server sa vo filmstripe neukáže, ale jeho latencia áno

Pri tomto vyšetrovaní sme našli aj druhú vec, ktorá so Speed Indexom priamo nesúvisí, ale zdržuje každý request: page cache pre anonymné stránky neobsluhovala. Podozrenie potvrdilo porovnanie prvého a druhého requestu na ten istý kľúč — TTFB bolo rovnaké (183–220 ms) a hlavička `X-Cache: HIT` chýbala. Zapnutý bol len OPcache a FPM pool, nie HTML cache.

Popri tom sa ukázal aj problém v jej upratovaní. Prune bežal celý na requeste raz za päť minút a čítal všetky záznamy:

| záznamov | pred | po |
| --- | --- | --- |
| 1 000 | 130 ms | 16,6 ms |
| 3 000 | 382 ms | 22,8 ms |
| 10 000 | — | 38,6 ms |
| 30 000 | — | 89,7 ms |

Riešením bolo preskočiť záznamy, ktoré od posledného behu nemohli expirovať (podľa `mtime`), a zastaviť sa po 4 MB prečítaných dát. Pri 30 000 záznamoch a 2,3 GB adresára to z 382 ms spravilo 90 ms — a hlavne to prestalo byť nepredvídateľné.

Pointa je rovnaká ako pri Speed Indexe: číslo bez kontextu je dohad. „Prune je pomalý" sa nedá opraviť, kým nenameriate, koľko stojí pri 1 000 a koľko pri 30 000 záznamoch.

## Čo si z toho odniesť

- Keď sa SI rozchádza s FCP a LCP, prvá otázka nie je „čo optimalizovať", ale „čo sa deje medzi snímkami".
- Filmstrip je dôkaz; metriky sú zhrnutie. Pri spore vyhráva snímka.
- Každú hypotézu sa snažte vyvrátiť meraním (blokovanie assetu, iný throttling), nie úsudkom.
- A overte aj to, čo v reporte nie je: hlavičky, TTFB, logy. Niekedy je najdrahšia vec práve to, čo sa nemeria.
