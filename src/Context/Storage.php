@todo 

Storage holds the logic how to store and retrieve session data for specific DataModel or DataModelArray

It converts DataModel to array and vice versa when saving and reading from storage.

Should we pass only the key instead of full SessionIdentity? (no)

Should storage manage keys ot storageDriver? (I guess... storage)