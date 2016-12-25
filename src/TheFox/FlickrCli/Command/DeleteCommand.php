<?php

namespace TheFox\FlickrCli\Command;

use Exception;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
// use OAuth\Common\Consumer\Credentials;
// use OAuth\OAuth1\Signature\Signature;
// use OAuth\Common\Storage\Memory;
use Rezzza\Flickr\Metadata;
use Rezzza\Flickr\ApiFactory;
use Rezzza\Flickr\Http\GuzzleAdapter as RezzzaGuzzleAdapter;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
// use Monolog\Handler\ErrorLogHandler;
use Carbon\Carbon;

class DeleteCommand extends Command{
	
	public $exit = 0;
	
	/**
	 * @var string The name of the configuration file. Defaults to 'config.yml'.
	 */
	private $configPath;
	
	private $logDirPath;
	private $log;
	private $logFilesFailed;
	
	protected function configure(){
		$this->setName('delete');
		$this->setDescription('Delete Photosets.');
		
		$this->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config file. Default: config.yml');
		
		$this->addArgument('photosets', InputArgument::IS_ARRAY, 'Photosets to use.');
		
		$this->configPath = 'config.yml';
		$this->logDirPath = 'log';
	}
	
	protected function execute(InputInterface $input, OutputInterface $output){
		$this->signalHandlerSetup();
		
		// Load and check the configuration file.
		if($input->hasOption('config') && $input->getOption('config')){
			$this->configPath = $input->getOption('config');
		}
		$filesystem = new Filesystem();
		if(!$filesystem->exists($this->configPath)){
			print 'ERROR: config file not found: '.$this->configPath."\n";
			return 1;
		}
		$config = Yaml::parse($this->configPath);
		if(
			!isset($config)
			|| !isset($config['flickr'])
			|| !isset($config['flickr']['consumer_key'])
			|| !isset($config['flickr']['consumer_secret'])
		){
			print 'ERROR: config invalid'."\n";
			return 1;
		}
		
		if($input->hasOption('log') && $input->getOption('log')){
			$this->logDirPath = $input->getOption('log');
		}
		if(!$filesystem->exists($this->logDirPath)){
			$filesystem->mkdir($this->logDirPath);
		}
		
		$photosets = $input->getArgument('photosets');
		
		// Set up the Flickr API.
		$metadata = new Metadata($config['flickr']['consumer_key'], $config['flickr']['consumer_secret']);
		$metadata->setOauthAccess($config['flickr']['token'], $config['flickr']['token_secret']);
		$apiFactory = new ApiFactory($metadata, new RezzzaGuzzleAdapter());
		$xml = $apiFactory->call('flickr.photosets.getList');
		
		$now = Carbon::now();
		$nowFormated = $now->format('Ymd');
		
		$logFormatter = new LineFormatter("[%datetime%] %level_name%: %message%\n");
		$this->log = new Logger('flickr_deleter');

		$logHandlerStderr = new StreamHandler('php://stderr', Logger::DEBUG);
		$logHandlerStderr->setFormatter($logFormatter);
		$this->log->pushHandler($logHandlerStderr);

		$logHandlerFile = new StreamHandler($this->logDirPath.'/flickr_delete_'.$nowFormated.'.log', Logger::INFO);
		$logHandlerFile->setFormatter($logFormatter);
		$this->log->pushHandler($logHandlerFile);
		
		$logFilesFailedStreamFilePath = $this->logDirPath.'/flickr_delete_files_failed_'.$nowFormated.'.log';
		$logFilesFailedStream = new StreamHandler($logFilesFailedStreamFilePath, Logger::INFO);
		$logFilesFailedStream->setFormatter($logFormatter);
		$this->logFilesFailed = new Logger('flickr_deleter');
		$this->logFilesFailed->pushHandler($logFilesFailedStream);
		
		$this->log->info('[main] delete files');
		
		$photosetsTitles = array();
		foreach($xml->photosets->photoset as $n => $photoset){
			if($this->exit){
				break;
			}
			
			$photosetsTitles[(int)$photoset->attributes()->id] = (string)$photoset->title;
		}
		
		asort($photosetsTitles);
		
		foreach($photosetsTitles as $photosetId => $photosetTitle){
			if($this->exit){
				break;
			}
			
			if(in_array($photosetTitle, $photosets)){
				
				$xmlPhotoList = $apiFactory->call('flickr.photosets.getPhotos', array('photoset_id' => $photosetId));
				$xmlPhotoListPagesTotal = (int)$xmlPhotoList->photoset->attributes()->pages;
				$xmlPhotoListPhotosTotal = (int)$xmlPhotoList->photoset->attributes()->total;
				
				$this->log->info('[photoset] '.$photosetTitle.': '.$xmlPhotoListPhotosTotal);
				
				$fileCount = 0;
				for($page = 1; $page <= $xmlPhotoListPagesTotal; $page++){
					if($this->exit){
						break;
					}
					
					if($page > 1){
						$xmlPhotoListOptions = array(
							'photoset_id' => $photosetId,
							'page' => $page,
						);
						$xmlPhotoList = $apiFactory->call('flickr.photosets.getPhotos', $xmlPhotoListOptions);
						
						var_export($xmlPhotoList);
						print "\n\n";
					}
					
					foreach($xmlPhotoList->photoset->photo as $n => $photo){
						if($this->exit){
							break;
						}
						
						$fileCount++;
						$id = (string)$photo->attributes()->id;
						try{
							$apiFactory->call('flickr.photos.delete', array('photo_id' => $id));
							$this->log->info('[photo] '.$page.'/'.$fileCount.' delete '.$id);
						}
						catch(Exception $e){
							$this->log->error('[photo] '.$page.'/'.$fileCount.' delete '.$id.' FAILED');
							$this->logFilesFailed->error($id);
						}
					}
				}
			}
			
		}
		
		return 0;
	}
	
	private function signalHandlerSetup(){
		if(function_exists('pcntl_signal')){
			declare(ticks = 1);
			
			pcntl_signal(SIGTERM, array($this, 'signalHandler'));
			pcntl_signal(SIGINT, array($this, 'signalHandler'));
			pcntl_signal(SIGHUP, array($this, 'signalHandler'));
		}
	}
	
	private function signalHandler($signal){
		$this->exit++;
		
		switch($signal){
			case SIGTERM:
				break;
			case SIGINT:
				print PHP_EOL;
				break;
			case SIGHUP:
				break;
			case SIGQUIT:
				break;
			case SIGKILL:
				break;
			case SIGUSR1:
				break;
			default:
		}
		
		if($this->exit >= 2){
			exit(1);
		}
	}
	
}