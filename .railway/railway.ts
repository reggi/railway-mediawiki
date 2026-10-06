import {defineRailway, github, postgres, project, service, volume} from 'railway/iac'
import packageJson from '../package.json' with {type: 'json'}

const packageName = 'name' in packageJson && typeof packageJson.name === 'string' ? packageJson.name : null
const secretGenerator = 'secret(64, "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789")'

if (!packageName) {
  throw new Error('package.json#name is required; resolve Knitto inputs before planning')
}

export default defineRailway(context => {
  const database = postgres('Postgres')
  const uploads = volume('MediaWiki uploads', {
    alerts: {usage: {'80': {}, '95': {}, '100': {}}},
    allowOnlineResize: true,
    sizeMB: 5000,
  })
  const domain = `${packageName}-${context.randomString('public-domain', 4)}.up.railway.app`

  const mediawiki = service('MediaWiki', {
    source: github('reggi/railway-mediawiki'),
    build: {
      builder: 'DOCKERFILE',
      dockerfilePath: 'Dockerfile',
    },
    domains: [domain],
    deploy: {
      restartPolicyMaxRetries: 10,
      restartPolicyType: 'ON_FAILURE',
    },
    env: {
      MW_ADMIN_PASSWORD: {generator: secretGenerator},
      MW_ADMIN_USER: {value: 'Admin'},
      MW_AUTH_TOKEN_VERSION: {value: '1'},
      MW_LANGUAGE_CODE: {value: 'en'},
      MW_SECRET_KEY: {generator: secretGenerator},
      MW_SERVER: {value: 'https://${{RAILWAY_PUBLIC_DOMAIN}}'},
      MW_SITE_NAME: {value: 'MediaWiki'},
      MW_TIMEZONE: {value: 'UTC'},
      MW_UPGRADE_KEY: {generator: secretGenerator},
      PGDATABASE: database.env.PGDATABASE,
      PGHOST: database.env.PGHOST,
      PGPASSWORD: database.env.PGPASSWORD,
      PGPORT: database.env.PGPORT,
      PGUSER: database.env.PGUSER,
    },
    volumeMounts: {
      '/var/www/html/images': uploads,
    },
  })

  return project(packageName, {
    resources: [mediawiki, database, uploads],
  })
})
