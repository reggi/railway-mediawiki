const githubConfig = require('@github/prettier-config')

module.exports = {
  ...githubConfig,
  overrides: [
    ...(githubConfig.overrides ?? []),
    {
      files: '.knitto/files/workflows/*.yml.hbs',
      options: {
        parser: 'yaml',
      },
    },
  ],
}
