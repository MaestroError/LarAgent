<?php

namespace LarAgent\Tests\LarAgent\Fakes;

class FakeWeatherService
{
    public function getWeather($location)
    {
        return "Weather for {$location} from WeatherService";
    }

    public static function getWeatherStatic($location)
    {
        return "Weather for {$location} from StaticWeatherService";
    }
}
