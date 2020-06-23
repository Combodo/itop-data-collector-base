@Library('itop-test-infra@infralibrary')

def infra = new Infra()

node {
  checkout scm

  infra.call()
}