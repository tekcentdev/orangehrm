pipeline {
    agent { label 'HKLIN03' }

    environment {
        PHP_VERSION = '8.3'
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
    }
}
