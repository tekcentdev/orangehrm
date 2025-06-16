pipeline {
    agent { label 'HKLIN03' }

    environment {
        PHP_VERSION = '8.3'
        DEPLOY_PATH = '/var/www/html/orangehrm' // will be overridden dynamically
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
                dir('src/client') {
                    script {
                        // Check if frontend files changed
                        def changed = sh(script: "git diff --name-only HEAD~1 HEAD", returnStdout: true).trim()
                        def frontendChanged = changed.contains('src/client') || changed.contains('package.json')

                        if (frontendChanged) {
                            echo 'Frontend changes detected – proceeding with build.'
                            
                            sh '''
                                export NVM_DIR="$HOME/.nvm"
                                [ -s "$NVM_DIR/nvm.sh" ] && \\. "$NVM_DIR/nvm.sh"
                                
                                # Only install if not already present
                                nvm install 18.20.8 || true
                                nvm use 18.20.8

                                export PATH="$HOME/.nvm/versions/node/v18.20.8/bin:$PATH"

                                # Use local cache for Yarn
                                yarn config set cache-folder .yarn-cache

                                echo "Installing dependencies with cache..."
                                yarn install --prefer-offline --frozen-lockfile

                                echo "Building frontend..."
                                yarn build
                            '''
                        } else {
                            echo 'No frontend changes — skipping build step.'
                        }
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

                    // Save to file so shell step can use it
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
                    sh """
                    DEPLOY_PATH=\$(cat deploy.path)

                    echo "Deploying to \$DEPLOY_USER@\${DEPLOY_HOST}:\$DEPLOY_PATH"
                    rsync -avz --no-times -e "ssh -i \$SSH_KEY -o StrictHostKeyChecking=no" \
                    --exclude='.git' --exclude='tests' \
                    ./ \
                    \$DEPLOY_USER@\${DEPLOY_HOST}:\$DEPLOY_PATH
                    """
                }
            }
        }
    }
}
