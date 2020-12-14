
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
              sh 'mkdir logs'
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
          echo 'I succeeeded! (Author: ${env.CHANGE_AUTHOR})'
      }
      unstable {
          echo 'I am unstable (Author: ${env.CHANGE_AUTHOR}) :/'
      }
      failure {
          echo 'I failed (Author: ${env.CHANGE_AUTHOR}) :('
      }
      changed {
          echo 'Things were different before... (Author: ${env.CHANGE_AUTHOR})'
      }
  }
}