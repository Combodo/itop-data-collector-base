
pipeline {
    options {
            buildDiscarder(logRotator(numToKeepStr: "100"))
    }

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

    stage('phpstan tests') {
      steps {
        script {
              sh 'mkdir -p logs'
              sh 'vendor/bin/phpstan analyse --error-format=junit > logs/phpstan_results.xml'
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
        script {
          echo 'I am unstable :/'
            rocketSend(channel: "#ci-commit", color: 'yellow', emoji: ':woozy_face:', rawMessage: true, message: "Oh no! ${JOB_NAME_UNESCAPED} Build is unstable! (${currentBuild.result}), Author: ${GIT_AUTHOR}, sha1: ${SHORT_SHA1}), (${env.BUILD_URL})")
        }
      }
      failure {
        script {
          echo 'I failed :('
          rocketSend(channel: "#ci-commit", color: 'red', emoji: ':sob:', rawMessage: true, message: "Oh no! ${JOB_NAME_UNESCAPED} Build failed! (${currentBuild.result}), Author: ${GIT_AUTHOR}, sha1: ${SHORT_SHA1}), (${env.BUILD_URL})")
        }
      }
      fixed {
        script {
          rocketSend(channel: "#ci-commit", color: 'green', emoji: ':love_you_gesture:', rawMessage: true, message: "Yes! ${JOB_NAME_UNESCAPED} Build repaired! (${currentBuild.result}), Author: ${GIT_AUTHOR}, sha1: ${SHORT_SHA1}), (${env.BUILD_URL})")            
        }
      }
  }

  environment {
    JOB_NAME_UNESCAPED = env.JOB_NAME.replaceAll("%2F", "/")
    GIT_AUTHOR = sh(
      returnStdout: true,
      script: 'git log -n 1|grep Author|sed -e "s/.*Author: //g"|sed -e "s/<.*//g"'
    )
    SHORT_SHA1 = sh(
      returnStdout: true,
      script: "echo ${GIT_COMMIT}|cut -c1-8"
    )
  }
}