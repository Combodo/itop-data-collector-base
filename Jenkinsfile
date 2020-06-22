def code

node {
  checkout scm

  code = load '/var/lib/jenkins/workspace/itop-test-infra_infralibrary/infra.groovy'

  code.call()
  //code.call('test/ci_description.ini')
}