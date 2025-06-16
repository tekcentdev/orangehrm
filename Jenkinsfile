pipeline {
    agent { label 'HKLIN01 || HKLIN02 || HKLIN03 || HKLIN04' }

    environment {
        PHP_VERSION = '8.3'
    }

    stages {
        stage('Verifying') {
            steps {
                compareGitBranch()
            }
        }

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
                        yarn install
                        yarn build
                    '''
                }
            }
        }

        stage('Publish Artifacts') {
            steps {
                publishArtifacts()
            }
        }
    }
}
