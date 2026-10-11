mw.hook('wikipage.content').add(function ($content) {
  const headings = $content[0].querySelectorAll('h1[id], h2[id], h3[id], h4[id], h5[id], h6[id]')

  for (const heading of headings) {
    if (!heading.id || heading.querySelector('.heading-permalink')) continue

    const link = document.createElement('a')
    const label = `Link to section: ${heading.textContent.trim()}`
    link.className = 'heading-permalink'
    link.setAttribute('href', `#${encodeURIComponent(heading.id)}`)
    link.setAttribute('aria-label', label)
    link.setAttribute('title', label)
    link.textContent = '#'
    heading.appendChild(link)
  }
})
