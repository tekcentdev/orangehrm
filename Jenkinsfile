pipeline {
    agent { label 'HKLIN03' }

    environment {
        PHP_VERSION = '8.3'
        DEPLOY_PATH = '/var/www/html/orangehrm' // Default path
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

        stage('Build Frontend & Installer') {
            parallel {
                stage('Build Frontend') {
                    steps {
                        dir('src/client') {
                            echo '⚙️ Building frontend (src/client)...'
                            sh '''
                                export NVM_DIR="$HOME/.nvm"
                                [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"
                                nvm install 18.20.8 || true
                                nvm use 18.20.8
                                export PATH="$HOME/.nvm/versions/node/v18.20.8/bin:$PATH"

                                echo "Installing Yarn globally"
                                npm install -g yarn

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
                }

                stage('Build Installer') {
                    steps {
                        dir('installer/client') {
                            echo '⚙️ Building installer (installer/client)...'
                            sh '''
                                export NVM_DIR="$HOME/.nvm"
                                [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"
                                nvm install 18.20.8 || true
                                nvm use 18.20.8
                                export PATH="$HOME/.nvm/versions/node/v18.20.8/bin:$PATH"

                                echo "Installing Yarn globally"
                                npm install -g yarn

                                echo "🔍 Verifying tools"
                                which node || echo "❌ node not found"
                                node -v || true
                                which yarn || echo "❌ yarn not found"
                                yarn -v || true

                                echo "📦 Installing dependencies with cache"
                                yarn config set cache-folder .yarn-cache
                                yarn install --prefer-offline --frozen-lockfile

                                echo "🏗️ Building installer..."
                                yarn build

                                echo "📁 Build output:"
                                ls -lh dist || echo "❌ dist folder not created"
                            '''
                        }
                    }
                }
            }
        }

        stage('Determine Environment') {
            steps {
                script {
                    def deployPath = ''
                    if (env.BRANCH_NAME ==~ /^dev\/.*/ || env.BRANCH_NAME ==~ /^feature\/.*/) {
                        deployPath = '/var/www/html/orangehrm/test'
                    } else if (env.BRANCH_NAME == 'main' || env.BRANCH_NAME ==~ /^release\/.*/) {
                        deployPath = '/var/www/html/orangehrm/prod'
                    } else {
                        error("❌ Branch '${env.BRANCH_NAME}' is not allowed to deploy.")
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
                        echo "🚀 Deploying to $DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH"

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
