# Episciences Citations

![GPL](https://img.shields.io/github/license/CCSDForge/episciences-citations)
![Language](https://img.shields.io/github/languages/top/CCSDForge/episciences-citations)

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

 