pipeline {
    agent { label 'HKLIN03' }

    environment {
        PHP_VERSION = '8.3'
    }

    environment {
        DEPLOY_USER = 'deployer'
        DEPLOY_HOST = '10.88.1.39'
        DEPLOY_PATH = '/var/www/html/orangehrm'
        SSH_KEY = '/home/administrator/.ssh/id_rsa'
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

        stage('Build Frontend') {
            steps {
                dir('src/client') {
                    echo "Installing and building frontend using Yarn"
                    sh '''                       
                        export NVM_DIR="$HOME/.nvm"
                        [ -s "$NVM_DIR/nvm.sh" ] && \\. "$NVM_DIR/nvm.sh"
                        nvm install 18.20.8
                        nvm use 18.20.8

                        export PATH="/home/administrator/.nvm/versions/node/v18.20.2/bin:$PATH"
                        yarn install
                        yarn build
                    '''
                }
            }
        }

        stage('Determine Environment') {
            steps {
                script {
                    if (env.BRANCH_NAME ==~ /^dev\/.*/ || env.BRANCH_NAME ==~ /^feature\/.*/) {
                        env.DEPLOY_PATH = '/var/www/html/orangehrm/test'
                    } else if (env.BRANCH_NAME == 'main' || env.BRANCH_NAME ==~ /^release\/.*/) {
                        env.DEPLOY_PATH = '/var/www/html/orangehrm/prod'
                    } else {
                        error("Branch '${env.BRANCH_NAME}' is not allowed to deploy.")
                    }
                }
            }
        }

        stage('Deploy') {
            steps {
                echo "Deploying branch ${env.BRANCH_NAME} to ${env.DEPLOY_PATH}"
                sh """
                rsync -avz -e "ssh -i $SSH_KEY -o StrictHostKeyChecking=no" \
                ./web/ \
                $DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH/web
                """
            }
        }
    }
}
