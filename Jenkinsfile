@Library('itop-test-infra@infralibrary') import Infra

def infra = new Infra()

node {
  checkout scm

  infra.call()
}