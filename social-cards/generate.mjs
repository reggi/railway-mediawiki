#!/usr/bin/env node
import {readFile} from 'node:fs/promises'
import {realpathSync} from 'node:fs'
import {fileURLToPath} from 'node:url'
import {createHash} from 'node:crypto'
import satori from 'satori'
import {Resvg} from '@resvg/resvg-js'
import {template} from './template.mjs'

const maxInputBytes = 4 * 1024 * 1024
const fontPaths = [
  import.meta.resolve('@fontsource/inter/files/inter-latin-400-normal.woff'),
  import.meta.resolve('@fontsource/inter/files/inter-latin-700-normal.woff'),
]

export async function fingerprint() {
  const hash = createHash('sha256')
  for (const path of [
    new URL('./generate.mjs', import.meta.url),
    new URL('./template.mjs', import.meta.url),
    new URL('./package-lock.json', import.meta.url),
    ...fontPaths.map(path => new URL(path)),
  ]) {
    hash.update(await readFile(path))
  }
  return hash.digest('hex')
}

export function validate(payload) {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    throw new Error('Expected an object.')
  }
  const fields = ['schema', 'renderer', 'width', 'height', 'branding', 'title', 'description', 'image']
  if (Object.keys(payload).length !== fields.length || fields.some(key => !(key in payload))) {
    throw new Error('Unexpected payload fields.')
  }
  if (
    payload.schema !== 1 ||
    payload.width !== 1200 ||
    payload.height !== 630 ||
    !/^[a-f0-9]{64}$/.test(payload.renderer)
  ) {
    throw new Error('Unsupported rendering contract.')
  }
  for (const [key, limit] of [
    ['branding', 100],
    ['title', 200],
    ['description', 500],
  ]) {
    if (
      typeof payload[key] !== 'string' ||
      Array.from(payload[key]).length > limit ||
      (!payload[key] && key !== 'description')
    ) {
      throw new Error(`Invalid ${key}.`)
    }
  }
  if (payload.image !== null) {
    if (typeof payload.image !== 'string' || !/^data:image\/(png|jpeg);base64,[A-Za-z0-9+/]+=*$/.test(payload.image)) {
      throw new Error('Expected an embedded PNG or JPEG image.')
    }
    const bytes = Buffer.from(payload.image.slice(payload.image.indexOf(',') + 1), 'base64')
    const png = bytes.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]))
    const jpeg = bytes[0] === 255 && bytes[1] === 216 && bytes[2] === 255
    if (!png && !jpeg) throw new Error('Invalid embedded image signature.')
  }
  return payload
}

export async function render(payload) {
  validate(payload)
  const fonts = await Promise.all(
    fontPaths.map(async (path, index) => ({
      name: 'Inter',
      data: await readFile(fileURLToPath(path)),
      weight: index === 0 ? 400 : 700,
      style: 'normal',
    })),
  )
  const svg = await satori(template(payload), {width: 1200, height: 630, fonts})
  return new Resvg(svg, {font: {loadSystemFonts: false}}).render().asPng()
}

async function main() {
  if (process.argv.length === 3 && process.argv[2] === '--fingerprint') {
    process.stdout.write(`${await fingerprint()}\n`)
    return
  }
  if (process.argv.length !== 2) throw new Error('Usage: /generate [--fingerprint]')
  const chunks = []
  let size = 0
  for await (const chunk of process.stdin) {
    size += chunk.length
    if (size > maxInputBytes) throw new Error('Payload exceeds 4 MiB.')
    chunks.push(chunk)
  }
  const payload = JSON.parse(Buffer.concat(chunks).toString('utf8'))
  if (payload.renderer !== (await fingerprint())) throw new Error('Renderer fingerprint does not match.')
  process.stdout.write(await render(payload))
}

if (process.argv[1] && fileURLToPath(import.meta.url) === realpathSync(process.argv[1])) {
  main().catch(() => {
    process.stderr.write('Social card rendering failed: invalid input or rendering error.\n')
    process.exitCode = 1
  })
}
