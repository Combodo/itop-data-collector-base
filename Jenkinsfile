@Library('itop-test-infra@infralibrary') import *

def infra = new Infra()

node {
  checkout scm

  infra.call()
}