import assert from 'node:assert/strict'
import {readFileSync} from 'node:fs'
import test from 'node:test'
import {runInNewContext} from 'node:vm'

const source = readFileSync(new URL('./heading-links.js', import.meta.url), 'utf8')

test('heading permalinks preserve fragments, labels, and content without duplicates', () => {
  let callback
  const headings = ['Overview', 'Café_&_connections', ''].map(id => ({
    id,
    textContent: 'Section title',
    children: [],
    querySelector() {
      return this.children.find(child => child.className === 'heading-permalink')
    },
    appendChild(child) {
      this.children.push(child)
    },
  }))
  runInNewContext(source, {
    mw: {
      hook(name) {
        assert.equal(name, 'wikipage.content')
        return {
          add(handler) {
            callback = handler
          },
        }
      },
    },
    document: {
      createElement(tag) {
        assert.equal(tag, 'a')
        return {
          attributes: {},
          setAttribute(name, value) {
            this.attributes[name] = value
          },
        }
      },
    },
  })
  const content = [
    {
      querySelectorAll(selector) {
        assert.equal(selector, 'h1[id], h2[id], h3[id], h4[id], h5[id], h6[id]')
        return headings
      },
    },
  ]
  callback(content)
  callback(content)

  for (const heading of headings.slice(0, 2)) {
    assert.equal(heading.children.length, 1)
    const link = heading.children[0]
    assert.equal(link.attributes.href, `#${encodeURIComponent(heading.id)}`)
    assert.equal(link.attributes['aria-label'], 'Link to section: Section title')
    assert.equal(link.attributes.title, link.attributes['aria-label'])
    assert.equal(link.textContent, '#')
    assert.equal(heading.textContent, 'Section title')
  }
  assert.equal(headings[2].children.length, 0)
})
