# VDataStorageSystems
- Store data in the form of arrays, update, quickly retrieve data, and store optimally securely in multiple storage formats in PocketMine-PMMP 5.

# Features
- Store batch data and update it, retrieve data quickly.
- Store data securely even when the server shuts down unexpectedly.
- Reduce the need for continuous data storage and continuous queries in `database` and local file formats such as `yml`.
- Supports a variety of formats such as: `.properties, .js, .json, .yml, .yaml, .sl, .txt, .list, .enum, mysql, sqlite`
- Allow data to be queried only once to minimize over-querying!

# Virion Required
- [LibVapmPMMP](https://github.com/VennDev/LibVapmPMMP)
- [VapmDatabasePMMP](https://github.com/VennDev/VapmDatabasePMMP)

# Example plugins
- [PluginExample](https://github.com/VennDev/TestVDataStorageSystems)

# Example some methods
```php
...
use VDataStorageSystems;

self::setPeriodTask(10 * 60); // Save all data every 10 minutes

// Create a storage with the name "test.yml" and type "YAML"
self::createStorage(
    name: $this->getDataFolder() . "test.yml",
    type: TypeDataStorage::TYPE_YAML
);
self::getStorage($this->getDataFolder() . "test.yml")->set("test", "test");

// Create a storage with the name "test.db" and type "SQLITE"
self::createStorage(
    name: "testSQLITE",
    type: TypeDataStorage::TYPE_SQLITE,
    database: new SQLite($this->getDataFolder() . "test.db")
);
self::getStorage("testSQLITE")->set("test", ["testAC", "testB"]);
// This is an example of how to use the Async class to get data from the database
new Async(function () {
    $data = Async::await(self::getStorage("testSQLITE")->get("test"));
    var_dump($data);
});

// Create a storage with the name "test" and type "MYSQL"
self::createStorage(
    name: "testMYSQL",
    type: TypeDataStorage::TYPE_MYSQL,
    database: new MySQL(
        host: "localhost",
        username: "root",
        password: "",
        databaseName: "testg",
        port: 3306
    )
);
self::getStorage("testMYSQL")->set("test", ["testAC", "testB"]);
// This is an example of how to use the Async class to get data from the database
new Async(function () {
    $data = Async::await(self::getStorage("testMYSQL")->get("test"));
    var_dump($data);
});
```

# Credits
- API Designer: [VennDev](https://github.com/VennDev)
- Paypal: pnam5005@gmail.com
