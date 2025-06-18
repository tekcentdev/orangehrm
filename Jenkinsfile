pipeline {
    agent { label 'HKLIN03' }

    environment {
        PHP_VERSION = '8.3'
        DEPLOY_PATH = '/var/www/html/orangehrm' // Default path, overridden by branch
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

                                echo "🔧 Checking for Yarn..."
                                if ! command -v yarn >/dev/null 2>&1; then
                                    echo "🔧 Yarn not found. Installing..."
                                    npm install -g yarn
                                else
                                    echo "✅ Yarn already installed: $(yarn -v)"
                                fi

                                echo "📦 Installing dependencies with Yarn 4"
                                yarn install

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

                                echo "🔧 Checking for Yarn..."
                                if ! command -v yarn >/dev/null 2>&1; then
                                    echo "🔧 Yarn not found. Installing..."
                                    npm install -g yarn
                                else
                                    echo "✅ Yarn already installed: $(yarn -v)"
                                fi

                                echo "📦 Installing dependencies with Yarn 4"
                                yarn install

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

        stage('Deploy .env file') {
            steps {
                script {
                    def deployPath = readFile('deploy.path').trim()
                    def envPrefix = (deployPath.contains('/test')) ? 'test' : 'prod'

                    def dbCredsId = "ohrm_db_credentials_${envPrefix}"
                    def dbHostId = "ohrm_db_host_${envPrefix}"
                    def dbNameId = "ohrm_db_name_${envPrefix}"

                    def credentials = [
                        usernamePassword(credentialsId: dbCredsId, usernameVariable: 'DB_USER', passwordVariable: 'DB_PASS'),
                        string(credentialsId: dbHostId, variable: 'DB_HOST'),
                        string(credentialsId: dbNameId, variable: 'DB_NAME'),
                        string(credentialsId: 'ohrm_cookie_domain', variable: 'COOKIE_DOMAIN'),
                        string(credentialsId: 'CF_APP_LAUNCHER_URL', variable: 'CF_APP_URL')
                    ]

                    withCredentials(credentials) {
                        def envContent = """
                        OHRM_DB_HOST=${DB_HOST}
                        OHRM_DB_USER=${DB_USER}
                        OHRM_DB_PASS=${DB_PASS}
                        OHRM_DB_NAME=${DB_NAME}
                        OHRM_SESSION_NAME=orangehrm
                        CF_LAUNCHER=${CF_APP_URL}
                        COOKIE_DOMAIN=${COOKIE_DOMAIN}
                        """.stripIndent()

                        writeFile file: '.env.generated', text: envContent

                        withCredentials([
                            string(credentialsId: 'orangehrm-deploy-user', variable: 'DEPLOY_USER'),
                            string(credentialsId: 'orangehrm-deploy-host', variable: 'DEPLOY_HOST'),
                            sshUserPrivateKey(credentialsId: 'orangehrm-ssh-key', keyFileVariable: 'SSH_KEY')
                        ]) {
                            sh """
                                scp -i $SSH_KEY -o StrictHostKeyChecking=no .env.generated $DEPLOY_USER@$DEPLOY_HOST:$deployPath/.env                                
                            """
                        }
                    }
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
                        --exclude='.git' --exclude='tests' --exclude='.env.generated' \
                        ./ \
                        $DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH
                    '''
                }
            }
        }
    }
}
