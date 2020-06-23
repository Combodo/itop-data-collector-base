@Library('itop-test-infra@infralibrary') _

def infra = new Infra()

node {
  checkout scm

  infra.call()
}