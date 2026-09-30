const { expect } = require('@playwright/test');

/**
 * Theme-aware icon visibility gate.
 *
 * Icons render via inline SVG with stroke/fill = currentColor. A theme that
 * ships a wrong token makes them invisible while DOM-level tests stay green —
 * the exact regression this guards (legacy .icon.icon-* spans rendered 0×0;
 * a recoloured icon on a matching backdrop fails the same way visually).
 *
 * Contrast is measured against the REAL rendered backdrop: we screenshot the
 * scrollport in viewport-height tiles and take the ring of pixels just
 * outside each icon's bounding box as the effective background. That resolves
 * gradients, translucent overlay circles, and colour-function backgrounds
 * honestly — an ancestor-walk over getComputedStyle misreads color(srgb 0–1)
 * values and cannot see gradients.
 *
 * Measurement is batched: one screenshot + one in-page analysis pass per
 * scroll tile. The previous per-icon scroll/screenshot/evaluate loop needed
 * 3–4 protocol round-trips × icons × schemes and starved the 60s test budget
 * on icon-dense pages (tickets list ≈ 200 icons).
 *
 * Per surface, asserts under both emulated schemes:
 *   - zero legacy .icon.icon-* spans
 *   - every rendered svg.tc-icon has a non-zero box
 *   - every rendered icon keeps WCAG 1.4.11 non-text contrast ≥3:1
 */
async function assertIconsAcrossSchemes(page) {
	const iconHandles = await page.locator('svg.tc-icon').elementHandles();
	const iconCount = iconHandles.length;

	for (const scheme of ['light', 'dark']) {
		await page.emulateMedia({ colorScheme: scheme });

		expect(
			await page.locator('span.icon[class*="icon-"]').count(),
			`legacy .icon.icon-* spans (${scheme})`,
		).toBe(0);

		// Fresh per scheme — light-scheme ratios must never satisfy the dark pass.
		const results = new Array(iconCount).fill(undefined); // ratio | 'clipped' | 'hidden'
		const hostInfo = await page.evaluate(() => {
			// Tag the element that actually scrolls the icon set (app shell, not
			// the document) so the tile sweep can drive it directly. The FIRST
			// icon can live in a non-scrolling region (sidebar nav) — pick the
			// scrollable ancestor shared by the most icons instead.
			const icons = document.querySelectorAll('svg.tc-icon');
			const counts = new Map();
			for (const svg of icons) {
				let s = svg.parentElement;
				while (
					s
					&& !(s.scrollHeight > s.clientHeight + 10 && /auto|scroll/.test(getComputedStyle(s).overflowY))
				) {
					s = s.parentElement;
				}
				counts.set(s, (counts.get(s) || 0) + 1);
			}
			let best = null;
			let bestN = 0;
			for (const [el, n] of counts) {
				if (el && n > bestN) {
					best = el;
					bestN = n;
				}
			}
			if (best) {
				best.setAttribute('data-tc-icon-scroller', '');
			}
			return [...document.querySelectorAll('svg.tc-icon')].map(
				(svg) => `${svg.parentElement.tagName}.${svg.parentElement.className}`,
			);
		});

		const vw = page.viewportSize();
		const scrollInfo = await page.evaluate(() => {
			const s = document.querySelector('[data-tc-icon-scroller]');
			if (!s) {
				return { h: document.documentElement.scrollHeight, vh: window.innerHeight, doc: true, top: 0, left: 0, right: window.innerWidth, bottom: window.innerHeight };
			}
			const sr = s.getBoundingClientRect();
			// The scroller's visible rect: content sliding under the fixed NC
			// header reports viewport rects above the scroller's clip edge —
			// measuring there samples header pixels, not the icon's backdrop.
			return { h: s.scrollHeight, vh: s.clientHeight, doc: false, top: sr.top, left: sr.left, right: sr.right, bottom: sr.bottom };
		});

		const maxTop = Math.max(0, scrollInfo.h - scrollInfo.vh);
		const offsets = [];
		for (let y = 0; y < maxTop; y += scrollInfo.vh) {
			offsets.push(y);
		}
		offsets.push(maxTop);

		for (const top of offsets) {
			await page.evaluate((t) => {
				const s = document.querySelector('[data-tc-icon-scroller]');
				if (s) {
					s.scrollTop = t;
				} else {
					window.scrollTo(0, t);
				}
			}, top);
			// Flush the repaint of the newly revealed region before capturing —
			// without it the shot can record unpainted (black) pixels for rows
			// whose rects already report as in-viewport.
			await page.evaluate(
				() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))),
			);

			const shot = await page.screenshot();
			const measured = await page.evaluate(
				({ b64, vw: w, vh: h, bnd, done }) => new Promise((resolve) => {
					const img = new Image();
					img.onload = () => {
						const c = document.createElement('canvas');
						c.width = img.width;
						c.height = img.height;
						const ctx = c.getContext('2d');
						ctx.drawImage(img, 0, 0);
						const px = ctx.getImageData(0, 0, img.width, img.height).data;
						const scale = img.width / w;
						const norm = (cssColor) => {
							const cc = document.createElement('canvas');
							cc.width = cc.height = 1;
							const cx = cc.getContext('2d');
							cx.fillStyle = cssColor;
							cx.fillRect(0, 0, 1, 1);
							return [...cx.getImageData(0, 0, 1, 1).data];
						};
						const lum = (r, g, b) => {
							const ch = [r, g, b].map((v) => {
								v /= 255;
								return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
							});
							return 0.2126 * ch[0] + 0.7152 * ch[1] + 0.0722 * ch[2];
						};
						const out = {};
						const icons = document.querySelectorAll('svg.tc-icon');
						for (let i = 0; i < icons.length; i++) {
							if (done.includes(i)) {
								continue;
							}
							const svg = icons[i];
							if (!svg.isConnected) {
								out[i] = 'hidden';
								continue;
							}
							const r = svg.getBoundingClientRect();
							const cs = getComputedStyle(svg);
							if (r.width < 2 || r.height < 2 || cs.display === 'none' || cs.visibility === 'hidden') {
								out[i] = 'hidden';
								continue;
							}
							const pad = 3;
							// The icon box must be fully inside the SCROLLER's
							// visible rect (not just the viewport): rows sliding
							// under the fixed header keep in-viewport rects while
							// their pixels are occluded — measuring there samples
							// the header, not the icon's real backdrop.
							const fullyIn = r.x >= bnd.l && r.y >= bnd.t
								&& r.x + r.width <= bnd.r && r.y + r.height <= bnd.b;
							if (!fullyIn) {
								if (r.bottom > bnd.t && r.top < bnd.b && r.right > bnd.l && r.left < bnd.r) {
									out[i] = 'clipped';
								}
								continue;
							}
							// Occlusion: a fixed/sticky layer (header, sticky
							// thead) can paint over an icon whose rect reads as
							// fully visible — the ring then samples the overlay,
							// not the real backdrop.
							const hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
							// Ancestors (hit.contains(svg)) are fine — icons are
							// often pointer-events:none so the button answers.
							if (hit && hit !== svg && !svg.contains(hit) && !hit.contains(svg)) {
								out[i] = 'clipped';
								continue;
							}
							const ix0 = Math.round(r.x * scale);
							const iy0 = Math.round(r.y * scale);
							const ix1 = Math.round((r.x + r.width) * scale);
							const iy1 = Math.round((r.y + r.height) * scale);
							const x0 = Math.max(0, Math.round((r.x - pad) * scale));
							const y0 = Math.max(0, Math.round((r.y - pad) * scale));
							const x1 = Math.min(img.width, Math.round((r.x + r.width + pad) * scale));
							const y1 = Math.min(img.height, Math.round((r.y + r.height + pad) * scale));
							const ring = [];
							for (let y = y0; y < y1; y++) {
								for (let x = x0; x < x1; x++) {
									const inside = x >= ix0 && x < ix1 && y >= iy0 && y < iy1;
									if (!inside) {
										const o = (y * img.width + x) * 4;
										ring.push(lum(px[o], px[o + 1], px[o + 2]));
									}
								}
							}
							if (ring.length < 8) {
								out[i] = 'clipped'; // too little backdrop sampled — treat as unmeasurable
								continue;
							}
							ring.sort((a, b) => a - b);
							const bgLum = ring[Math.floor(ring.length / 2)];
							const [fr, fg, fb] = norm(cs.color);
							const fgLum = lum(fr, fg, fb);
							const ratio = (Math.max(fgLum, bgLum) + 0.05) / (Math.min(fgLum, bgLum) + 0.05);
							// Sub-threshold tile readings are re-measured on the
							// targeted path (centered + paint-flushed) before they
							// may fail — kills mid-transition sampling artifacts
							// without lowering the bar.
							out[i] = ratio >= 3 ? ratio : 'suspect';
						}
						resolve(out);
					};
					img.onerror = () => resolve({});
					img.src = `data:image/png;base64,${b64}`;
				}),
				{
					b64: shot.toString('base64'),
					vw: vw.width,
					vh: vw.height,
					bnd: { l: scrollInfo.left, t: scrollInfo.top, r: scrollInfo.right, b: scrollInfo.bottom },
					done: results.map((v, i) => (typeof v === 'number' ? i : -1)).filter((i) => i >= 0),
				},
			);
			for (const [i, v] of Object.entries(measured)) {
				const idx = Number(i);
				if (typeof v === 'number' || results[idx] === undefined) {
					results[idx] = v; // a real measurement wins; 'clipped'/'hidden' only fill gaps
				}
			}
		}

		// Icons never fully visible in the tile sweep (nested scrollers, sticky
		// overlays) get one targeted measurement via the original clip path.
		for (let i = 0; i < iconCount; i++) {
			if (typeof results[i] === 'number' || results[i] === 'hidden') {
				continue;
			}
			const icon = iconHandles[i];
			// Center inside the scroller: minimal scrolling can leave the icon
			// under a sticky/fixed overlay, where the ring samples the overlay.
			await icon.evaluate((svg) => svg.scrollIntoView({ block: 'center', inline: 'center' }));
			// Paint flush before geometry + screenshot.
			await page.evaluate(
				() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))),
			);
			const occluded = await icon.evaluate((svg) => {
				const r = svg.getBoundingClientRect();
				const hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
				return !!(hit && hit !== svg && !svg.contains(hit) && !hit.contains(svg));
			});
			if (occluded) {
				results[i] = 'clipped'; // permanently covered by an overlay — unmeasurable
				continue;
			}
			const box = await icon.boundingBox();
			// Fully outside the scrollport after centering = not painted at all
			// (collapsed menus, offscreen panels). Nothing to contrast-check.
			if (
				!box
				|| box.width === 0
				|| box.y + box.height <= scrollInfo.top
				|| box.y >= scrollInfo.bottom
				|| box.x + box.width <= scrollInfo.left
				|| box.x >= scrollInfo.right
			) {
				results[i] = 'hidden';
				continue;
			}
			const m = await renderedContrast(page, icon, box);
			if (m === null) {
				results[i] = 'clipped';
			} else if (m.ratio < 3) {
				results[i] = `ratio=${m.ratio.toFixed(2)} fg=${m.fgLum.toFixed(3)} bg=${m.bgLum.toFixed(3)} img=${m.imgW}x${m.imgH} at[${i}]=${Math.round(box.x)},${Math.round(box.y)}`;
			} else {
				results[i] = m.ratio;
			}
		}

		const bad = [];
		for (let i = 0; i < iconCount; i++) {
			if (results[i] === 'clipped') {
				bad.push(`${hostInfo[i]} unmeasurable (viewport-clipped after scroll)`);
			} else if (typeof results[i] === 'number' && results[i] < 3) {
				bad.push(`${hostInfo[i]} ratio=${results[i].toFixed(2)}`);
			} else if (typeof results[i] === 'string' && results[i].startsWith('ratio=')) {
				bad.push(`${hostInfo[i]} ${results[i]}`);
			}
		}
		expect(bad, `icon contrast ≥3:1 (${scheme})`).toEqual([]);
	}
	await page.emulateMedia({ colorScheme: 'light' });
}

/**
 * Contrast ratio between the icon's painted colour and the rendered pixels
 * surrounding it. Returns null when the measurement is impossible.
 */
async function renderedContrast(page, icon, box) {
	const pad = 3;
	const vw = page.viewportSize();
	// boundingBox() after scrollIntoViewIfNeeded() reports VIEWPORT
	// coordinates — the same space a regular (non-fullPage) screenshot clip
	// uses. Do NOT use fullPage: it resizes the viewport during capture and
	// the document offsets no longer match the box.
	const clip = {
		x: Math.max(0, box.x - pad),
		y: Math.max(0, box.y - pad),
		width: box.width + 2 * pad,
		height: box.height + 2 * pad,
	};
	if (clip.x + clip.width > vw.width) {
		clip.width = vw.width - clip.x;
	}
	if (clip.y + clip.height > vw.height) {
		clip.height = vw.height - clip.y;
	}
	if (clip.width <= box.width / 2 || clip.height <= box.height / 2) {
		return null; // icon clipped by viewport edge even after scroll
	}
	const shot = await page.screenshot({ clip });
	return icon.evaluate(
		(svg, { b64, inner, w }) => {
			const norm = (cssColor) => {
				const c = document.createElement('canvas');
				c.width = c.height = 1;
				const ctx = c.getContext('2d');
				ctx.fillStyle = cssColor;
				ctx.fillRect(0, 0, 1, 1);
				return [...ctx.getImageData(0, 0, 1, 1).data];
			};
			const lum = (r, g, b) => {
				const c = [r, g, b].map((v) => {
					v /= 255;
					return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
				});
				return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
			};
			return new Promise((resolve) => {
				const img = new Image();
				img.onload = () => {
					const scale = img.width / w;
					const c = document.createElement('canvas');
					c.width = img.width;
					c.height = img.height;
					const ctx = c.getContext('2d');
					ctx.drawImage(img, 0, 0);
					const px = ctx.getImageData(0, 0, img.width, img.height).data;
					// Ring = pixels outside the icon's own box (scaled to image px).
					const [ix, iy, iw, ih] = inner.map((v) => Math.round(v * scale));
					const ring = [];
					for (let y = 0; y < img.height; y++) {
						for (let x = 0; x < img.width; x++) {
							const inside = x >= ix && x < ix + iw && y >= iy && y < iy + ih;
							if (!inside) {
								const o = (y * img.width + x) * 4;
								ring.push(lum(px[o], px[o + 1], px[o + 2]));
							}
						}
					}
					if (!ring.length) {
						resolve(null);
						return;
					}
					ring.sort((a, b) => a - b);
					const bgLum = ring[Math.floor(ring.length / 2)];
					const [fr, fg, fb] = norm(getComputedStyle(svg).color);
					const fgLum = lum(fr, fg, fb);
					resolve({
						ratio: (Math.max(fgLum, bgLum) + 0.05) / (Math.min(fgLum, bgLum) + 0.05),
						fgLum,
						bgLum,
						imgW: img.width,
						imgH: img.height,
					});
				};
				img.onerror = () => resolve(null);
				img.src = `data:image/png;base64,${b64}`;
			});
		},
		{
			b64: shot.toString('base64'),
			inner: [box.x - clip.x, box.y - clip.y, box.width, box.height],
			w: clip.width,
		},
	);
}

module.exports = { assertIconsAcrossSchemes };
