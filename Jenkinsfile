@Library('itop-test-infra@burp') _

def infra = new Infra()

node {
  checkout scm

  infra.call()
}