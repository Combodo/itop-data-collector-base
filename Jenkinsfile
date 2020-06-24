
pipeline {
  agent any
  stages {
    stage('composer install') {
      steps {
          sh 'composer install'
      }
    }

    stage('phpunit tests') {
      steps {
        script {
              sh 'mkdir logs/phpunit_results.xml'
              sh 'php vendor/bin/phpunit  --log-junit logs/phpunit_results.xml --configuration test/phpunit.xml --teamcity '
          }
      }
    }
  }

  post {
      always {
          echo 'One way or another, I have finished'

          archiveArtifacts allowEmptyArchive:true, artifacts: 'logs/*'

          junit testResults:'logs/*.xml', allowEmptyResults:false
      }
      success {
          echo 'I succeeeded!'
      }
      unstable {
          echo 'I am unstable :/'
      }
      failure {
          echo 'I failed :('
      }
      changed {
          echo 'Things were different before...'
      }
  }
}