@Library('itop-test-infra@infralibrary')

def infra

node {
  checkout scm

  infra = load '/var/lib/jenkins/workspace/itop-test-infra_infralibrary/src/infra.groovy'

  infra.call()
}

