# Episciences Citations Manager


![GPL](https://img.shields.io/github/license/CCSDForge/episciences-citations)
![Language](https://img.shields.io/github/languages/top/CCSDForge/episciences-citations)


Software for processing citations from [Episciences](https://www.episciences.org/) publication

The software is developed by the [Center for the Direct Scientific Communication (CCSD)](https://www.ccsd.cnrs.fr/en/).

### License
Episciences is free software licensed under the terms of the GPL Version 3. See LICENSE.


## Install project

Environment
```
change the .env to the right environment
```


Dependencies
```
If you want update libraries you can update with composer install/update
```

First install 
```
composer install

Migration :

php bin/console doctrine:migrations:migrate

```


Migration
```
Change in .env the database url 

Example : DATABASE_URL="mysql://db_user:db_password@127.0.0.1:3306/db_name?serverVersion=5.7"

To upgrade to the latest migration

php bin/console doctrine:migrations:migrate latest
```

Javascript
```
Launch Yarn install or update
```




---

**Application and service to manage/visualize citations**
## Install the application

---

### <ins>Configuration</ins>
Create Vhost


```composer install ``` in your app folder

Create .env.X , X related to your env (eg: .env.local)

Migrate database with ```php bin/console doctrine:migrations:migrate 'DoctrineMigrations\thelastversion'```

Less secure/control but fast ``` php bin/console d:s:u ```

Run ``` npm install ```

Run ``` npm run dev ```

 