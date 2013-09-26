<?php

namespace Sirian\YMLParser\Parser;

use Sirian\YMLParser\Exception\UnsupportedOfferTypeException;
use Sirian\YMLParser\Factory\Factory;
use Sirian\YMLParser\Shop;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Parser extends EventDispatcher
{
    protected $xmlReader;
    protected $factory;

    private $path = [];

    public function __construct(Factory $factory = null)
    {
        if (null == $factory) {
            $factory = new Factory();
        }

        $this->xmlReader = new \XMLReader();
        $this->factory = $factory;
    }

    public function parse($file)
    {
        $this->path = [];

        $this->xmlReader->open($file);
        $this->read();
        $this->xmlReader->close();
    }

    protected function read()
    {
        $shop = null;
        $xml = $this->xmlReader;
        while ($xml->read()) {
            if ($xml->nodeType == \XMLReader::END_ELEMENT) {
                array_pop($this->path);
            }

            if ($xml->nodeType == \XMLReader::ELEMENT) {
                array_push($this->path, $xml->name);
                $path = implode('/', $this->path);
                switch ($path) {
                    case 'yml_catalog/shop':
                        $shop = $this->factory->createShop();
                        $this->dispatch('shop', new ShopEvent($shop));
                        break;
                    case 'yml_catalog/shop/currencies':
                        $currencies = $this->parseCurrencies($shop);
                        $this->dispatch('currencies', new CurrenciesEvent($currencies));
                        break;
                    case 'yml_catalog/shop/categories':
                        $categories = $this->parseCategories($shop);
                        $this->dispatch('categories', new CategoriesEvent($categories));
                        break;
                    case 'yml_catalog/shop/offers/offer':
                        try {
                            $offer = $this->parseOffer($shop);
                        } catch (UnsupportedOfferTypeException $e) {
                            break;
                        }

                        $this->dispatch('offer', new OfferEvent($offer));
                        break;
                }
            }
        }
    }

    protected function parseCurrencies(Shop $shop)
    {
        $xml = $this->loadElementXml();
        foreach ($xml->currency as $elem) {
            $shop->addCurrency($this->createCurrency($elem, $shop));
        }
        return $shop->getCurrencies();
    }

    protected function parseCategories(Shop $shop)
    {
        $xml = $this->loadElementXml();

        $parents = [];
        foreach ($xml->category as $elem) {
            $shop->addCategory($this->createCategory($elem, $shop));
            $parents[(int)$elem['id']] = (int)$elem['parentId'];
        }


        foreach ($parents as $id => $parentId) {
            $parent = $shop->getCategory($parentId);
            $shop
                ->getCategory($id)
                ->setParent($parent)
            ;
        }
        return $shop->getCategories();
    }

    protected function parseOffer(Shop $shop)
    {
        $xml = $this->loadElementXml();

        $offer = $this->createOffer($xml, $shop);

        return $offer;
    }

    protected function createCurrency(\SimpleXMLElement $elem, Shop $shop)
    {
        $currency = $this->factory->createCurrency();
        $currency
            ->setId((string)$elem['id'])
            ->setRate((string)$elem['rate'])
            ->setPlus((int)$elem['plus'])
        ;

        return $currency;
    }

    protected function createCategory(\SimpleXMLElement $elem, Shop $shop)
    {
        $id = (int)$elem['id'];

        $parents[$id] = (int)$elem['parentId'];

        $category = $this->factory->createCategory();

        $category
            ->setId($id)
            ->setName((string)$elem)
        ;

        return $category;
    }

    protected function createOffer(\SimpleXMLElement $elem, Shop $shop)
    {
        $type = (string)$elem['type'];

        $offer = $this->factory->createOffer($type);
        $offer
            ->setId((int)$elem['id'])
            ->setAvailable(((string)$elem['available']) == 'true' ? true : false)
            ->setType($type)
        ;

        foreach ($elem as $field => $value) {
            foreach (['add', 'set'] as $method) {
                $method .= $this->camelize($field);
                if (method_exists($offer, $method)) {
                    call_user_func([$offer, $method], (string)$value);
                    break;
                }
            }
        }

        $currencyId = (string)$elem->currencyId;
        if ($shop->getCurrency($currencyId)) {
            $offer->setCurrency($shop->getCurrency($currencyId));
        }

        $categoryId = (string)$elem->categoryId;
        if ($shop->getCategory($categoryId)) {
            $offer->setCategory($shop->getCategory($categoryId));
        }
        return $offer;
    }

    protected function loadElementXml()
    {
        $xml = $this->xmlReader->readOuterXml();
        $this->xmlReader->next();
        array_pop($this->path);
        return simplexml_load_string($xml);
    }

    private function camelize($field)
    {
        return strtr(ucwords(strtr($field, array('_' => ' ', '.' => '_ '))), array(' ' => ''));
    }
}