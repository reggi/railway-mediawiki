import {defineRailway, project} from 'railway/iac'
import packageJson from '../package.json' with {type: 'json'}

const packageName = 'name' in packageJson && typeof packageJson.name === 'string' ? packageJson.name : null

if (!packageName) {
  throw new Error('package.json#name is required; resolve Knitto inputs before planning')
}

export default defineRailway(() =>
  project(packageName, {
    resources: [],
  }),
)
