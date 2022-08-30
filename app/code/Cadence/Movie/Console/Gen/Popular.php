<?php

namespace Cadence\Movie\Console\Gen;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use GuzzleHttp\Client;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ResponseFactory;
use Magento\Framework\Webapi\Rest\Request;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\State;

class Popular extends Command
{   
    const NAME = 'movies:generatepopular';
    
	const API_BASE_URI = 'https://api.themoviedb.org/3/movie/';
	const API_POPULAR_URI = 'https://api.themoviedb.org/3/movie/popular';
    const API_AUTH_V3_NO_BEARER = 'd7acd6403f2aba037a92a834d2af9097';

    /** The page number to the popular api, perhaps set this as a prameter to the command itself */
    const API_PAGE_NUMBER = 1;

    /**
     * @var ResponseFactory
     */
    private $responseFactory;

    /**
     * @var ClientFactory
     */
    private $clientFactory;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepositoryInterface;

    /**
     * @var ProductFactory
     */
    private $productFactory;

    /**
     * @var State
     */
    private $state;
    
    /**
     * @param ClientFactory $clientFactory
     * @param ResponseFactory $responseFactory
     * @param ProductRepositoryInterface $productRepositoryInterface
     * @param ProductFactory $productFactory
     * @param State $state
     */
    public function __construct(
        ClientFactory $clientFactory,
        ResponseFactory $responseFactory,
        ProductRepositoryInterface $productRepositoryInterface,
        ProductFactory $productFactory,
        State $state
    ){
        $this->clientFactory = $clientFactory;
        $this->responseFactory = $responseFactory;
        $this->productRepositoryInterface = $productRepositoryInterface;
        $this->productFactory = $productFactory;
        $this->state = $state;

        parent::__construct();
    }

	/**
	 * @return void
	 */
	protected function configure()
	{
		$this->setName(self::NAME)
			->setDescription(
                'Pulls top movies from IMDB database.
                Populates them as virtual products in your magento 2 catalogue labeled by category.'
            );
		parent::configure();
	}
    
    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * 
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
	{
        // Set the area access to adminhtml 
        $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);

        $output->writeln("Generating virtual products for popular imdb films...");
        $output->writeln("");

        // Fetch our popular movies from IMDB return as array of arrays
        $items = $this->fetchPopularMoviesAsArray();

        // Loop through all returned items
        // Create product for each item.
        // If a product cannot be added display the exception message
        // Otherwise save the product 
        foreach($items as $item) {
            try {
            $product = $this->createProduct($item);
            $this->saveProduct($product);
            } catch (\Exception $e){ $output->writeln($e->getMessage()); continue; }
            $output->writeln("Added '{$product->getName()}', with SKU: {$product->getSku()}");
        }
	}

    /**
     * Fetch the necessary items from IMDB database, merging in the data from 3 endpoints
     * popular, details, credits and return an array of formatted items as raw associative array data
     * OR temporary products (that need to be persisted to the db)
     * 
     * @param bool $convertArrayItemsToProducts : specifies if we convert each item to a product in this function 
     * or keep each item as an associative array to be converted later.
     * 
     * @return array
     */
    private function fetchPopularMoviesAsArray(bool $convertArrayItemsToProducts = false) : array
    {
        // Array to store our returned data
        $products = [];

        // Hit Popular route for the set page number as per imdbs docs 
        $resp = $this->doRequest(
            self::API_POPULAR_URI . 
            '?api_key=' . 
            self::API_AUTH_V3_NO_BEARER . 
            '&page='. 
            self::API_PAGE_NUMBER
        );
        $responseBody = $resp->getBody();
        $responseContent = $responseBody->getContents(); 
        $decodedJsonPopularResponse = json_decode($responseContent, true);
        foreach($decodedJsonPopularResponse['results'] as $obj) {
            if (!$obj['id']) {
                continue; 
            }
            // This reprsents the base structure for a parsed item that will become a product in our db
            // TODO: Inject Objects from static calls below to make dependency tree less difficult to reason about 
            $item = [
                'sku' => $obj['id'] ?? '',
                'name' => $obj['title'] ?? '',
                'description' => $obj['overview'] ?? '',
                'genre' => '',
                'actors' => '',
                'director' => '',
                'producer' => '',
                'vote_average' => null,
                'year' => null,
                'price' => 5.99,
                'qty' => 100,
                'status' => \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED,
                'type_id' => \Magento\Catalog\Model\Product\Type::TYPE_VIRTUAL,
                'attribute_set_id' => \Cadence\Movie\Helper\Config::MOVIE_ATTRIBUTE_SET_ID,
                'category_ids' => [\Cadence\Movie\Helper\Config::MOVIE_CATEGORY_ID]
            ];


            // Hit Details route
            $resp = $this->doRequest(
                self::API_BASE_URI . 
                $item['sku'] . 
                '?api_key=' .
                self::API_AUTH_V3_NO_BEARER
            );
            $responseBody = $resp->getBody();
            $responseContent = $responseBody->getContents(); 
            $decodedJsonDetailsResponse = json_decode($responseContent, true);
            foreach($decodedJsonDetailsResponse as $key => $value) {
                if($key == 'genres') {
                    $genres = array_column($value, 'name');
                    $genresStr = implode(',', $genres);
                    $item['genre'] = $genresStr; 
                }
                if($key == 'vote_average') {
                    $item['vote_average'] = $value;
                }
                if($key == 'release_date') {
                    $item['year'] = date('Y', strtotime($value));
                }
            }


            // Hit Credits route
            $resp = $this->doRequest(
                self::API_BASE_URI . 
                $item['sku'] . 
                '/credits' .
                '?api_key=' .
                self::API_AUTH_V3_NO_BEARER
            );
            $responseBody = $resp->getBody();
            $responseContent = $responseBody->getContents(); 
            $decodedJsonCreditsResponse = json_decode($responseContent, true);
            foreach($decodedJsonCreditsResponse as $key => $value) {
                if($key === 'cast') {
                    $castNames = array_column($value, 'name');
                    $namesStr = implode(',', $castNames);
                    $item['actors'] = $namesStr; 
                }
                if($key === 'crew') {
                    $directors = [];
                    $producers = [];
                    foreach($value as $member){
                        if(strtolower($member['job']) === 'director') {
                            $directors[] = $member['name'];
                        }
                        if(strtolower($member['job']) === 'producer') {
                            $producers[] = $member['name'];
                        }
                     
                    }
                    $dNamesStr = implode(',', $directors);
                    $pNamesStr = implode(',', $producers);
                    $item['director'] = $dNamesStr;
                    $item['producer'] = $pNamesStr;
                }
            }


            // determine if we do the conversion to temp products
            // or just return the raw items as array
            if($convertArrayItemsToProducts) {
                $products[] = $this->createProduct($item);
            } else {
                $products[] = $item;
            }
        }
        return $products;
    }

    /**
     * @param array $item : Represents a formatted IMDB item per our requirements 
     * 
     * @return \Magento\Catalog\Model\Product
     */
    private function createProduct(array $item) : Product
    {
        $product = $this->productFactory->create();
        $product->setName($item['name']);
        $product->setSku($item['sku']);
        $product->setPrice($item['price']);
        $product->setTypeId($item['type_id']);
        $product->setAttributeSetId($item['attribute_set_id']);
        
        // NOTE: I noticed msi was disabled, not sure if intentional so I set qty this way although it is depracated for sake of time.
        // I believe I would be able to do this with StockItemInterface and StockItemRepository however both
        // those are also deprecated. 
        $product->setQuantityAndStockStatus([
            'qty' => $item['qty'], 
            'is_in_stock' => $item['status'], 
            'manage_stock' => 1
        ]);

        $product->setData('description', $item['description']);
        $product->setData('category_ids', $item['category_ids']);

        // --- Set Custom Attributes --- // 
        $product->setData('year', $item['year']);
        $product->setData('vote_average', $item['vote_average']);
        $product->setData('genre',$item['genre']);
        $product->setData('actors', $item['actors']);
        $product->setData('director', $item['director']);
        $product->setData('producer', $item['producer']);

        return $product;
    }

    /**
     * Determine if the sku is already in our database, if not we save it.
     * 
     * @param Product $product
     * 
     * @return void
     * 
     * @throws \Exception 
     */
    private function saveProduct(Product $product) : void 
    {
        try {
            if($this->productRepositoryInterface->get($product->getSku())) {
                throw new \Exception("'{$product->getName()}', with SKU: {$product->getSku()} not added, already in database.");
                return ;
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {} // do nothing because we want to add it to the database
        $this->productRepositoryInterface->save($product, true);
    }

    /**
     * Do API request with provided params
     *
     * @param string $uriEndpoint
     * @param array $params
     * @param string $requestMethod
     *  
     * @return Response
     */
    private function doRequest(
        string $uriEndpoint = '', 
        array $params = [],
        string $requestMethod = Request::HTTP_METHOD_GET
    ) : Response
    {
        /** @var Client $client */
        $client = $this->clientFactory->create();
        try {
            $response = $client->request(
                $requestMethod,
                $uriEndpoint,
                $params
            );
        } catch (GuzzleException $exception) {
            /** @var Response $response */
            $response = $this->responseFactory->create([
                'status' => $exception->getCode(),
                'reason' => $exception->getMessage()
            ]);
        }
        return $response;
    }
}