stage('Build') {
    steps {
        script {
            def changed = sh(script: "git show --pretty='' --name-only", returnStdout: true).trim()

            def setupNodeScript = '''#!/bin/bash
                export NVM_DIR="$HOME/.nvm"
                [ -s "$NVM_DIR/nvm.sh" ] && \\. "$NVM_DIR/nvm.sh"
                nvm install 18.20.8 || true
                nvm use 18.20.8
                export PATH="$HOME/.nvm/versions/node/v18.20.8/bin:$PATH"
                yarn config set cache-folder .yarn-cache
                yarn install --prefer-offline --frozen-lockfile
                yarn build
            '''

            def buildFrontend = { dirPath, label ->
                dir(dirPath) {
                    echo "${label} changes detected – proceeding with build."
                    sh """
                        echo "Working in: \$(pwd)"
                        ${setupNodeScript}
                        ls -lh dist || echo "❌ ${dirPath}/dist not created"
                    """
                }
            }

            if (changed.contains('src/client') || changed.contains('package.json')) {
                buildFrontend('src/client', 'Frontend')
            } else {
                echo 'No frontend changes — skipping frontend build.'
            }

            if (changed.contains('installer/client') || changed.contains('package.json')) {
                buildFrontend('installer/client', 'Installer')
            } else {
                echo 'No installer changes — skipping installer build.'
            }
        }
    }
}
