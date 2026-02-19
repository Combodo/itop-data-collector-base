
pipeline {
    options {
            buildDiscarder(logRotator(numToKeepStr: "100"))
    }

  agent any

  parameters {
      string(name: 'phpstan_level', defaultValue: '1', description: 'level used for phpstan validation.')        
  }  
  stages {
    stage('composer install') {
      steps {
          sh '/usr/bin/php8.1 /usr/local/bin/composer install'
      }
    }

    stage('code style tests') {
      steps {
        script {
            catchError(buildResult: 'SUCCESS', stageResult: 'FAILURE') {
              sh 'bash test/php-code-style/validate.sh'
            }
          }
      }
    }

    stage('phpstan tests') {
      steps {
        script {
            catchError(buildResult: 'SUCCESS', stageResult: 'FAILURE') {
              sh 'mkdir -p logs'
              if ("${phpstan_level}" == ""){
                def phpstan_level = "1";
              }
              sh '/usr/bin/php8.1 vendor/bin/phpstan analyse -l ${phpstan_level} --error-format=junit > logs/phpstan_results.xml'
            }
          }
      }
    }

    stage('phpunit tests') {
      steps {
        script {
              sh 'mkdir -p logs'
              sh '/usr/bin/php8.1 vendor/bin/phpunit  --log-junit logs/phpunit_results.xml --configuration test/phpunit.xml --teamcity '
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
