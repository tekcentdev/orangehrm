pipeline {
    agent { label 'HKLIN03' }

    environment {
        PHP_VERSION = '8.3'
        DEPLOY_PATH = '/var/www/html/orangehrm' // Default fallback
    }

    stages {
        stage('Checkout Code') {
            steps {
                checkout scm
            }
        }

        stage('Determine Environment') {
            steps {
                script {
                    def deployPath = ''
                    if (env.BRANCH_NAME ==~ /^dev\/.*/ || env.BRANCH_NAME ==~ /^feature\/.*/ || env.BRANCH_NAME ==~ /^codex\/.*/) {
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

        stage('Install PHP Dependencies') {
            steps {
                dir('src') {
                    echo "📦 Installing Composer dependencies in src/"
                    sh '''
                        php -v
                        if [ -f composer.json ]; then
                            echo "Running composer install..."
                            composer install --no-interaction --prefer-dist
                        else
                            echo "❌ composer.json not found in src/"
                            exit 1
                        fi

                        if [ ! -f vendor/autoload.php ]; then
                            echo "❌ vendor/autoload.php missing after install."
                            exit 1
                        fi
                    '''
                }
            }
        }

        stage('Build') {
            parallel {
                stage('Frontend') {
                    steps {
                        script {
                            buildYarnProject('src/client')
                        }
                    }
                }
                stage('Installer') {
                    steps {
                        script {
                            buildYarnProject('installer/client')
                        }
                    }
                }
            }
        }

        stage('Generate Version Info') {
            steps {
                script {
                    def timestamp = new Date().format("yyyy-MM-dd HH:mm:ss", TimeZone.getTimeZone('Asia/Hong_Kong'))
                    def versionContent = """\
                        Branch: ${env.BRANCH_NAME}
                        Build: #${env.BUILD_NUMBER}
                        Built at: ${timestamp}
                    """.stripIndent()
                    
                    writeFile file: 'version.txt', text: versionContent
                }
            }
        }

        stage('Deploy') {
            steps {
                script {
                    def deployPath = readFile('deploy.path').trim()
                    def sharedPath = "${deployPath}/shared"
                    def envPrefix = (deployPath.contains('/test')) ? 'test' : 'prod'
                    def backupTargetDir = "/opt/backups/orangehrm/${envPrefix}"

                    def dbCredsId = "ohrm_db_credentials_${envPrefix}"
                    def dbHostId = "ohrm_db_host_${envPrefix}"
                    def dbNameId = "ohrm_db_name_${envPrefix}"

                    def calendarTokenId = "ohrm_calendar_access_token_${envPrefix}"
                    def domainId = "ohrm_domain_${envPrefix}"

                    def envCredentials = [
                        usernamePassword(credentialsId: dbCredsId, usernameVariable: 'DB_USER', passwordVariable: 'DB_PASS'),
                        string(credentialsId: dbHostId, variable: 'DB_HOST'),
                        string(credentialsId: dbNameId, variable: 'DB_NAME'),
                        string(credentialsId: 'ohrm_cookie_domain', variable: 'COOKIE_DOMAIN'),
                        string(credentialsId: 'CF_APP_LAUNCHER_URL', variable: 'CF_APP_URL'),
                        string(credentialsId: 'ohrm_backup_encryption_password', variable: 'ENCRYPTION_PASS'),
                        usernamePassword(credentialsId: 'orangehrm_sftp', usernameVariable: 'SFTP_USER', passwordVariable: 'SFTP_PASSWORD'),
                        string(credentialsId: 'orangehrm_sftp_port', variable: 'SFTP_PORT'),
                        string(credentialsId: 'orangehrm_sftp_host', variable: 'SFTP_HOST'),
                        string(credentialsId: calendarTokenId, variable: 'CALENDAR_ACCESS_TOKEN'),
                        string(credentialsId: domainId, variable: 'CALENDAR_DOMAIN')
                    ]

                    withCredentials(envCredentials) {
                        def envContent = """
                        OHRM_DB_HOST="${DB_HOST}"
                        OHRM_DB_USER="${DB_USER}"
                        OHRM_DB_PASS="${DB_PASS}"
                        OHRM_DB_NAME="${DB_NAME}"
                        OHRM_SESSION_NAME="orangehrm"
                        CF_LAUNCHER="${CF_APP_URL}"
                        COOKIE_DOMAIN="${COOKIE_DOMAIN}"
                        ENCRYPTION_PASS="${ENCRYPTION_PASS}"
                        SFTP_USER="${SFTP_USER}"
                        SFTP_PASSWORD="${SFTP_PASSWORD}"
                        SFTP_PORT="${SFTP_PORT}"
                        SFTP_HOST="${SFTP_HOST}"
                        CALENDAR_ACCESS_TOKEN="${CALENDAR_ACCESS_TOKEN}"
                        CALENDAR_DOMAIN="${CALENDAR_DOMAIN}"
                        """.stripIndent()
                      
                        writeFile file: '.env.generated', text: envContent
                    }

                    withCredentials([
                        string(credentialsId: 'orangehrm-deploy-user', variable: 'DEPLOY_USER'),
                        string(credentialsId: 'orangehrm-deploy-host', variable: 'DEPLOY_HOST'),
                        sshUserPrivateKey(credentialsId: 'orangehrm-ssh-key', keyFileVariable: 'SSH_KEY'),
                        file(credentialsId: 'cloudflare-public-key', variable: 'PEM_FILE')
                    ]) {
                        sh """
                            echo "🚀 Deploying to $DEPLOY_USER@$DEPLOY_HOST:$deployPath"

                            ssh -i $SSH_KEY -o StrictHostKeyChecking=no $DEPLOY_USER@$DEPLOY_HOST "mkdir -p $sharedPath $backupTargetDir"

                            # Deploy .env and PEM
                            scp -i $SSH_KEY -o StrictHostKeyChecking=no .env.generated $DEPLOY_USER@$DEPLOY_HOST:$sharedPath/.env
                            scp -i $SSH_KEY -o StrictHostKeyChecking=no $PEM_FILE $DEPLOY_USER@$DEPLOY_HOST:$sharedPath/cloudflare.pem

                            ssh -i $SSH_KEY -o StrictHostKeyChecking=no $DEPLOY_USER@$DEPLOY_HOST \\
                            "chown $DEPLOY_USER:www-data $sharedPath/.env $sharedPath/cloudflare.pem && \\
                            chmod 640 $sharedPath/.env $sharedPath/cloudflare.pem && \\
                            chmod 755 $sharedPath"

                            # Deploy backup scripts to environment-specific path
                            scp -i $SSH_KEY -o StrictHostKeyChecking=no backup/orangehrm_backup.sh $DEPLOY_USER@$DEPLOY_HOST:$backupTargetDir/orangehrm_backup.sh
                            scp -i $SSH_KEY -o StrictHostKeyChecking=no backup/orangehrm_restore.sh $DEPLOY_USER@$DEPLOY_HOST:$backupTargetDir/orangehrm_restore.sh
                            ssh -i $SSH_KEY -o StrictHostKeyChecking=no $DEPLOY_USER@$DEPLOY_HOST \\
                            "chmod 755 $backupTargetDir/orangehrm_backup.sh $backupTargetDir/orangehrm_restore.sh"

                            # Deploy application code
                            rsync -avz --no-times --no-perms -e "ssh -i $SSH_KEY -o StrictHostKeyChecking=no" \\
                            --exclude='.git' --exclude='tests' --exclude='.env.generated' --exclude='deploy.path' --exclude='Jenkinsfile' \\
                            ./ \\
                            $DEPLOY_USER@$DEPLOY_HOST:$deployPath
                        """
                    }
                }
            }
        }

    }

    // Reusable build function outside stages block
    post {
        always {
            echo "🧼 Build complete for branch: ${env.BRANCH_NAME}, build #${env.BUILD_NUMBER}"
        }
    }
}

def buildYarnProject(projectDir) {
    dir(projectDir) {
        echo "⚙️ Building Yarn project in ${projectDir}..."

        sh '''
            set -e

            export NVM_DIR="$HOME/.nvm"
            [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"
            nvm use 18 || nvm install 18

            echo "Node version in use:"
            node -v

            if [ ! -f yarn.lock ] || [ ! -f package.json ]; then
                echo "❌ yarn.lock or package.json not found!"
                exit 1
            fi

            echo "🧰 Checking for Yarn version 4.1.0..."

            CURRENT_YARN_VERSION=$(yarn --version 2>/dev/null || echo "none")
            if [ "$CURRENT_YARN_VERSION" != "4.1.0" ]; then
                echo "🔧 Setting up Corepack and Yarn 4.1.0..."
                corepack enable
                corepack prepare yarn@4.1.0 --activate
            else
                echo "✅ Yarn 4.1.0 already in place"
            fi

            echo "📦 Installing dependencies using yarn install --immutable"
            if ! yarn install --immutable; then
                echo "⚠️ Immutable install failed, retrying with clean install"
                rm -rf node_modules .yarn
                yarn install || { echo "❌ yarn install failed"; exit 1; }
            fi

            echo "🏗️ Running yarn build..."
            yarn build || { echo "❌ yarn build failed"; exit 1; }

            echo "📦 Build output:"
            ls -lh dist || ls -lh build || ls -lh .next || echo "❌ No build output"
        '''
    }
}
