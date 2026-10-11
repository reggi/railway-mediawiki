import assert from 'node:assert/strict'
import {spawnSync} from 'node:child_process'
import {fileURLToPath} from 'node:url'
import {test} from 'node:test'
import {Resvg} from '@resvg/resvg-js'
import {fingerprint, render, validate} from '../generate.mjs'
import {template} from '../template.mjs'

const script = fileURLToPath(new URL('../generate.mjs', import.meta.url))
const payload = {
  schema: 1,
  renderer: await fingerprint(),
  width: 1200,
  height: 630,
  branding: 'wiki.reggi.com',
  title: 'Security Camera Camcorder Rig',
  description: 'A portable camera system built around an analog security camera.',
  image: null,
}

test('renders deterministic real PNG bytes at the required dimensions', async () => {
  const png = await render(payload)
  assert.deepEqual(png.subarray(0, 8), Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]))
  assert.equal(png.readUInt32BE(16), 1200)
  assert.equal(png.readUInt32BE(20), 630)
  assert.deepEqual(png, await render(payload))
})

test('renders long text and Unicode without external resources', async () => {
  const png = await render({...payload, title: 'Über café '.repeat(20), description: 'Résumé & naïve — '.repeat(25)})
  assert.ok(png.length > 1000)
})

test('uses a plain white canvas without rounded framing or a background watermark', () => {
  const tree = template(payload)
  const styles = []
  const collect = node => {
    if (!node || typeof node !== 'object') return
    styles.push(node.props.style)
    const children = node.props.children
    for (const child of Array.isArray(children) ? children : [children]) collect(child)
  }
  collect(tree)
  assert.equal(tree.props.style.background, '#ffffff')
  assert.ok(styles.every(style => !('border' in style) && !('borderRadius' in style) && !('opacity' in style)))
  assert.ok(styles.some(style => style.fontWeight === 700 && style.lineHeight >= 1.2))
  assert.ok(styles.some(style => style.fontSize === 16 && style.color === '#9297a0'))
  assert.ok(!JSON.stringify(tree).includes('PROJECT'))
})

test('renders an embedded infobox image with the two-column template', async () => {
  const image = new Resvg(
    '<svg xmlns="http://www.w3.org/2000/svg" width="500" height="800"><rect width="500" height="800" fill="#786448"/></svg>',
  )
    .render()
    .asPng()
  const png = await render({...payload, image: `data:image/png;base64,${image.toString('base64')}`})
  assert.equal(png.readUInt32BE(16), 1200)
  assert.equal(png.readUInt32BE(20), 630)
})

test('CLI accepts stdin and emits only PNG to stdout', () => {
  const result = spawnSync(process.execPath, [script], {input: JSON.stringify(payload), maxBuffer: 8 * 1024 * 1024})
  assert.equal(result.status, 0, result.stderr.toString())
  assert.equal(result.stderr.length, 0)
  assert.equal(result.stdout.readUInt32BE(16), 1200)
})

test('CLI exposes a reproducible renderer and asset fingerprint', () => {
  const result = spawnSync(process.execPath, [script, '--fingerprint'])
  assert.equal(result.status, 0)
  assert.equal(result.stdout.toString().trim(), payload.renderer)
})

test('rejects malformed, excessive, stale and network-enabled payloads', () => {
  for (const input of ['{', JSON.stringify({...payload, renderer: '0'.repeat(64)}), ' '.repeat(4 * 1024 * 1024 + 1)]) {
    const result = spawnSync(process.execPath, [script], {input, maxBuffer: 8 * 1024 * 1024})
    assert.notEqual(result.status, 0)
    assert.equal(result.stdout.length, 0)
    assert.match(result.stderr.toString(), /rendering failed/)
  }
  assert.throws(() => validate({...payload, image: 'https://example.com/photo.png'}))
  assert.throws(() => validate({...payload, title: 'x'.repeat(201)}))
  assert.throws(() => validate({...payload, outputPath: '/tmp/untrusted.png'}))
})
