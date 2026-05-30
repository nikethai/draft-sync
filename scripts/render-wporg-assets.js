/**
 * Render WordPress.org listing assets from SVG sources.
 *
 * Produces:
 *   assets/wporg/icon-128x128.png
 *   assets/wporg/icon-256x256.png
 *   assets/wporg/banner-772x250.png
 *   assets/wporg/banner-1544x500.png
 *   assets/wporg/screenshot-1.png
 *   assets/wporg/screenshot-2.png
 *   assets/wporg/screenshot-3.png
 *   assets/wporg/screenshot-4.png
 *
 * Run from repo root: node scripts/render-wporg-assets.js
 */
const fs = require('fs');
const path = require('path');
const { Resvg } = require('@resvg/resvg-js');

const root = path.resolve(__dirname, '..');
const src  = path.join(root, 'assets/wporg');
const targets = [
	{ svg: 'icon.svg',         png: 'icon-128x128.png',     w: 128,  h: 128  },
	{ svg: 'icon.svg',         png: 'icon-256x256.png',     w: 256,  h: 256  },
	{ svg: 'banner.svg',       png: 'banner-772x250.png',   w: 772,  h: 250  },
	{ svg: 'banner.svg',       png: 'banner-1544x500.png',  w: 1544, h: 500  },
	{ svg: 'screenshot-1.svg', png: 'screenshot-1.png',     w: 1200, h: 900  },
	{ svg: 'screenshot-2.svg', png: 'screenshot-2.png',     w: 1200, h: 900  },
	{ svg: 'screenshot-3.svg', png: 'screenshot-3.png',     w: 1200, h: 900  },
	{ svg: 'screenshot-4.svg', png: 'screenshot-4.png',     w: 1200, h: 900  },
];

for (const t of targets) {
	const svgPath = path.join(src, t.svg);
	if (!fs.existsSync(svgPath)) {
		console.error(`Missing SVG source: ${svgPath}`);
		process.exit(1);
	}
	const svg = fs.readFileSync(svgPath, 'utf8');
	const resvg = new Resvg(svg, {
		fitTo: { mode: 'width', value: t.w },
		background: 'rgba(0,0,0,0)',
		font: { loadSystemFonts: true },
	});
	const pngData = resvg.render();
	const png = pngData.asPng();
	const outPath = path.join(src, t.png);
	fs.writeFileSync(outPath, png);
	const sizeKB = (png.length / 1024).toFixed(1);
	console.log(`  ✓ ${t.png} (${t.w}x${t.h}, ${sizeKB} KB)`);
}

console.log('\nDone.');
