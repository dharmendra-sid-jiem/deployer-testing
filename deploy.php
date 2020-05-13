<?php
namespace Deployer;

require 'recipe/zend_framework.php';
#require 'vendor/deployer/recipes/recipe/slack.php';

// Project repository
//set('dantai_repository', 'git@github.com:jiem-inc/jiem-portal-dist.git');
set('dantai_repository', 'https://github.com/jiem-inc/jiem-portal-dist.git');
set('repository', 'https://github.com/jiem-inc/jiem-portal-dist.git');
//''
// [Optional] Allocate tty for git clone. Default value is false.
set('git_tty', true); 
set('build_path', __DIR__ . '/.build');
set('common_application_path', '/home/ec2-user/deployer/domain.com');
set('worker_application_path', '/home/ec2-user/deployer/worker');
set('web_application_path', '/home/ec2-user/deployer/web');

set('web_host','ds-deployer-dev');
set('worker_host','ds-deployer-dev-test');
set('db_host','ds-deployer-dev');

set('keep_releases', 10);
//Slack Configuration
#set('slack_webhook', 'https://hooks.slack.com/services/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');
#set('slack_title', 'Deployer Testing');
set('user', 'ec2-user');
set('instances',[
					[
						'instanceId'=>'i-0ad0851c3ea7f47ac', 
						'instanceName'=>'Deployer-dev'
					]
				]);
set('database_name', 'jiem-dantai-portal-dev');
// Shared files/dirs between deploys 
#add('shared_files', []);
#add('shared_dirs', []);
// Writable dirs by web server 
add('writable_dirs', [
						'data/logs',
						'data/cronlog',
						'data/agrexEDI',
						'data/DoctrineORMModule',
						'data/DoctrineORMModule/Proxy',
						'data/einavi',
						'data/pdfTemplate',
						'data/htmlTemplate',
						'data/econtextCombini',
						'data/DoctrineORMModule'
					]);

set('http_user', 'ec2-user');		 //apache			

// Hosts
host('ds-deployer-dev', 'ds-deployer-dev-test')
	->stage('development')
	->user(get('user')) 
	->configFile('ssh_config')
	->identityFile(' ~/.ssh/keys/jiem_dantai_portal_key_pair.pem')
    ->set('deploy_path', '{{common_application_path}}')
	//->set('repository', '{{dantai_repository}}')
	->forwardAgent(true)
	->multiplexing(false)
	->set('branch', 'test'); //this will be removed and used tag for release 

//Database operations on worker server
task('deploy:database:migrate', function () {
	desc("Database migration start");
	//run(' cd {{worker_application_path}} && ./doctrine-module migration:migrate --allow-no-migration');
	writeln(' cd {{worker_application_path}} && ./doctrine-module migration:migrate --allow-no-migration  --write-sql');
	desc("Database migration completed");
})->onHosts('{{db_host}}');

task('deploy:database:downgrade', function () {
	desc("Database downgrading start");
	//run(' cd {{worker_application_path}} && ./doctrine-module migration:migrate --allow-no-migration');
	writeln(' cd {{worker_application_path}} && ./doctrine-module migration:migrate prev --allow-no-migration  --write-sql');
	desc("Database downgrading completed");
})->onHosts(get('db_host'));


//Released source code copy to application directory on web and worker servers
task('deploy:worker:copy', function () {
	desc("Source code copying on worker server");
	run( 'if [ ! -d {{worker_application_path}} ]; then sudo mkdir -p {{worker_application_path}}; fi' );
	run( 'sudo cp -R {{common_application_path}}/current/* {{worker_application_path}}' );	
	desc("Source code copying completed on worker server");
})->onHosts(get('worker_host'));

task('deploy:web:copy', function () {
	desc("Source code copying on web server");
	run( 'if [ ! -d {{web_application_path}} ]; then sudo mkdir -p {{web_application_path}}; fi' );
	run( 'sudo cp -R {{common_application_path}}/current/* {{web_application_path}}' );
	desc("Source code copying completed on web server");	
})->onHosts(get('web_host'));


//Image creation

task('local:createImages', function () {
	desc("AWS instance image creation start");
	set('deploy_path', '{{build_path}}');
	$releaseSeq = get('release_name');
	$date = date('Ymd');
	$instances = get('instances');
	//Create Image of multiple application servers.
	foreach($instances as $instance){
		//$instanceName-release-$releaseSeq-$YMD
		$imageName = $instance['instanceName'].'-release-'.$releaseSeq.'-'.$date;
		$instanceId = $instance['instanceId'];
		//run('aws ec2 create-image --instance-id '.$instanceId.' --name "'.$imageName.'" --no-reboot --region ap-northeast-1');
		writeln('aws ec2 create-image --instance-id '.$instanceId.' --name "'.$imageName.'" --no-reboot --region ap-northeast-1');
	}
	desc("AWS instance image creation completed");
})->local();


task('local:createDbSnapshot', function () {
	desc("AWS RDS snapshot creation start");
	set('deploy_path', '{{build_path}}');
	$releaseSeq = get('release_name');
	$date = date('Ymd');
	//Create Image of multiple application servers.
	//$DBIdentifier-release-$releaseSeq-20201231
	$dbInstanceIdentifier = get('database_name'); 
	$dbSnapshotIdentifier = $dbInstanceIdentifier.'-release-'.$releaseSeq.'-'.$date;
	//run('aws rds create-db-snapshot  --db-instance-identifier "'.$dbInstanceIdentifier.'"    --db-snapshot-identifier "'.$dbSnapshotIdentifier.'" --region ap-northeast-1');
	writeln('aws rds create-db-snapshot  --db-instance-identifier "'.$dbInstanceIdentifier.'"    --db-snapshot-identifier "'.$dbSnapshotIdentifier.'" --region ap-northeast-1');
	desc("AWS RDS snapshot creation completed");
})->local();

//Create build on build server


task('build', function () {
	desc("Build creation started on build server");
    set('deploy_path', '{{build_path}}');
    invoke('deploy:prepare');
    invoke('deploy:release');
    invoke('deploy:update_code');
    invoke('deploy:vendors');
    invoke('deploy:symlink');
	desc("Build creation completed");
	desc("Composer installation will start");
	run('cd {{deploy_path}}/current/ && composer install');
	desc("Composer installation completed");

})->local();


task('upload', function () {
	desc("Build upload start");
    upload('{{build_path}}/current/', '{{release_path}}');
	desc("Build upload completed");
});

task('deploy', [
	'local:createImages',
	'local:createDbSnapshot',
    'build',	
    'deploy:prepare',
    'deploy:release',
    'upload',
    'deploy:shared',
    'deploy:writable',
    'deploy:symlink',
    'deploy:worker:copy',
	'deploy:web:copy', 
	'deploy:database:migrate',
	'cleanup',
    'success'
]);

#before('deploy', 'slack:notify');
#after('deploy', 'slack:notify:success');

///Rollback operations 
task('revert', [
	'deploy:database:downgrade',
    'rollback',
    'deploy:worker:copy',
	'deploy:web:copy'	
]);