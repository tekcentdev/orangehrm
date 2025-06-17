pipeline {
    agent { label 'HKLIN03' }

    environment {
        PHP_VERSION = '8.3'
        DEPLOY_PATH = '/var/www/html/orangehrm' // overridden dynamically
    }

    stages {

        stage('Checkout Code') {
            steps {
                checkout scm
            }
        }

        stage('Install PHP Dependencies') {
            steps {
                dir('src') {
                    echo "Installing PHP dependencies using Composer"
                    sh '''
                        php -v
                        if [ -f composer.json ]; then
                            composer install --no-interaction --prefer-dist
                        else
                            echo "composer.json not found, skipping Composer install."
                        fi
                    '''
                }
            }
        }

        stage('Build') {
    steps {
        script {
            def changed = sh(script: "git show --pretty='' --name-only", returnStdout: true).trim()

            def buildClient = { dirPath, label ->
                dir(dirPath) {
                    echo "${label} changes detected – proceeding with build."

                    sh '''
                        echo "🔧 Setting up Node environment"
                        export NVM_DIR="$HOME/.nvm"
                        [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
                        nvm install 18.20.8 || true
                        nvm use 18.20.8
                        export PATH="$HOME/.nvm/versions/node/v18.20.8/bin:$PATH"

                        echo "🔍 Verifying tools"
                        which node || echo "❌ node not found"
                        node -v || true
                        which yarn || echo "❌ yarn not found"
                        yarn -v || true

                        echo "📦 Installing dependencies with cache"
                        yarn config set cache-folder .yarn-cache
                        yarn install --prefer-offline --frozen-lockfile

                        echo "🏗️ Building frontend..."
                        yarn build

                        echo "📁 Build output:"
                        ls -lh dist || echo "❌ dist folder not created"
                    '''
                }
            }

            if (changed.contains('src/client') || changed.contains('package.json')) {
                buildClient('src/client', 'Frontend')
            } else {
                echo 'No frontend changes — skipping build step.'
            }

            if (changed.contains('installer/client') || changed.contains('package.json')) {
                buildClient('installer/client', 'Installer')
            } else {
                echo 'No installer changes — skipping build step.'
            }
        }
    }
}


        stage('Determine Environment') {
            steps {
                script {
                    if (env.BRANCH_NAME ==~ /^dev\/.*/ || env.BRANCH_NAME ==~ /^feature\/.*/) {
                        deployPath = '/var/www/html/orangehrm/test'
                    } else if (env.BRANCH_NAME == 'main' || env.BRANCH_NAME ==~ /^release\/.*/) {
                        deployPath = '/var/www/html/orangehrm/prod'
                    } else {
                        error("Branch '${env.BRANCH_NAME}' is not allowed to deploy.")
                    }

                    writeFile file: 'deploy.path', text: deployPath
                }
            }
        }

        stage('Deploy') {
            steps {
                withCredentials([
                    string(credentialsId: 'orangehrm-deploy-user', variable: 'DEPLOY_USER'),
                    string(credentialsId: 'orangehrm-deploy-host', variable: 'DEPLOY_HOST'),
                    sshUserPrivateKey(credentialsId: 'orangehrm-ssh-key', keyFileVariable: 'SSH_KEY')
                ]) {
                    sh '''
                    DEPLOY_PATH=$(cat deploy.path)
                    echo "Deploying to $DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH"
                    rsync -avz --no-times --no-perms -e "ssh -i $SSH_KEY -o StrictHostKeyChecking=no" \
                    --exclude='.git' --exclude='tests' \
                    ./ \
                    $DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH
                    '''
                }
            }
        }
    }
}
