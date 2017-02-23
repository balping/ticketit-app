<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Validation\Validator;
use DB;
use Kordy\Ticketit\Models\Configuration;
use Kordy\Ticketit\Models\Priority;
use Kordy\Ticketit\Models\Category;
use Kordy\Ticketit\Models\Status;

class InstallTicketit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ticketit:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sets up the database as required for running Ticketit. Run this only once, before making any modifications to project files.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->welcome();
        $this->createDatabase();
        $this->migrate();
        $this->registerAdmin();
        $this->enableTicketit();
        $this->setupTicketit();
        $this->installPresets();
        $this->setUpMail();
        $this->removeInstaller();
    }

    private function testDbConnection(){
        $this->line('Checking database connection...');

        try{
            DB::connection(DB::getDefaultConnection())->reconnect();
        }catch(\Exception $e){
            return false;
        }

        $this->info('Database connection working.');
        return true;
    }

    private function createDatabase(){
        if($this->testDbConnection()){
            return;
        }
        

        $this->comment('Database connection is not set up.');
        $this->line("You need to choose a database type.");
        $this->line("If yours is not listed below, use the documentation for further config:");
        $this->line("https://laravel.com/docs/master/database#configuration");

        retry:

        $connection = null;
        $host = null;
        $port = null;
        $database = null;
        $username = null;
        $password = null;


        $connection = $this->choice('Choose a connection type', array_keys(config('database.connections')));

        if($connection == "sqlite"){
            $path = database_path('database.sqlite');
            touch($path);
            $this->info('Database file created at ' . $path);
        }else{
            $defaultPort = $connection == "mysql" ? 3306 : ($connection == "pgsql" ? 5432 : null);

            $host = $this->ask('Database host', 'localhost');
            $port = $this->ask('Database port', $defaultPort);
            $database = $this->ask('Database name');
            $username = $this->ask('Database username');
            $password = $this->secret('Database password');

        }

        $this->writeNewEnvironmentFileWith(compact('connection', 'host', 'port', 'database', 'username', 'password'));

        if(!$this->testDbConnection()){
            $this->error('Could not connect to database.');
            goto retry;
        }

    }

    /**
     * Write a new environment file with the given database settings.
     *
     * @param  string  $key
     * @return void
     */
    private function writeNewEnvironmentFileWith($settings)
    {

        DB::purge(DB::getDefaultConnection());

       

        foreach($settings as $key => $value){
            $key = 'DB_' . strtoupper($key);
            $line = $value ? ($key . '=' . $value) : $key;
            putenv($line);
            file_put_contents($this->laravel->environmentFilePath(), preg_replace(
                '/^' . $key . '.*/m',
                $line,
                file_get_contents($this->laravel->environmentFilePath())
            ));
        }

        config()->offsetSet("database", include(config_path('database.php')));
        
    }

    private function welcome(){
        $this->line("Welcome to Ticketit!\n");
        $this->line("You can use Ctrl+C to exit the installer any time.\n");
    }

    private function migrate(){
        $this->line("\nStarting migration...");
        $this->call('migrate');
        $this->line("");
    }

    private function registerAdmin(){
        $this->line("Registering admin user...");

        $translator = app(\Illuminate\Translation\Translator::class);
        $validator = new Validator($translator, [], []);
        $validator->setPresenceVerifier(app()['validation.presence']);

        //ask email

        $validator->setRules(["email" => "required|email|max:255|unique:users"]);

        do{
            $email = $this->ask("Email");

            $validator->setData(compact('email'));

            $passes = $validator->passes();

            if(!$passes){
                collect($validator->messages()->all())->each(function($message){
                    $this->error("\n\n[ERROR] $message\n");
                });
            }

        }while(!$passes);

        //ask name

        $validator->setRules(["name" => "required|max:255"]);

        do{
            $name = $this->ask("Name");

            $validator->setData(compact('name'));

           $passes = $validator->passes();

            if(!$passes){
                collect($validator->messages()->all())->each(function($message){
                    $this->error("\n\n[ERROR] $message\n");
                });
            }

        }while(!$passes);

        //ask password

        $validator->setRules(["password" => "required|min:6|confirmed"]);

        do{
            $password = $this->secret("Password");
            $password_confirmation = $this->secret("Confirm password");

            $validator->setData(compact('password', 'password_confirmation'));

            $passes = $validator->passes();

            if(!$passes){
                collect($validator->messages()->all())->each(function($message){
                    $this->error("\n\n[ERROR] $message\n");
                });
            }

        }while(!$passes);

        \App\User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt($password)
        ]);

        $this->info("Admin user created.\n");
    }

    private function enableTicketit(){
        $_SERVER['ARTISAN_TICKETIT_INSTALLING'] = true;

        file_put_contents(config_path('app.php'), str_replace(
            '//Kordy\Ticketit\TicketitServiceProvider::class,',
            'Kordy\Ticketit\TicketitServiceProvider::class,',
            file_get_contents(config_path('app.php'))
        ));

        app()->register(\Kordy\Ticketit\TicketitServiceProvider::class);
    }

    private function setupTicketit(){

        $this->line("Setting up Ticketit...");

        $installController = new \Kordy\Ticketit\Controllers\InstallController;

        $request = new \Illuminate\Http\Request();
        $request->offsetSet('master', 'another');
        $request->offsetSet('other_path', 'views/layouts/app.blade.php');
        $request->offsetSet('admin_id', \App\User::orderBy('id', 'desc')->first()->id);

        \Auth::login(\App\User::find($request->admin_id));

        $installController->setup($request);
    }

    private function installPresets(){
        $this->line("Installing presets...");

        $newStatus = Status::create([
            "name"    => "New",
            "color"    => "#e9551e",
        ]);

        $closedStatus = Status::create([
            "name"    => "Closed",
            "color"    => "#186107",
        ]);

        $reopenedStatus = Status::create([
            "name"    => "Re-opened",
            "color"    => "#71001f",
        ]);

        Configuration::where('slug', 'default_status_id')->first()->update(['value' => $newStatus->id]);
        Configuration::where('slug', 'default_close_status_id')->first()->update(['value' => $closedStatus->id]);
        Configuration::where('slug', 'default_reopen_status_id')->first()->update(['value' => $reopenedStatus->id]);

        \Cache::forget('settings');

        Priority::create([
            "name"    => "High",
            "color"    => "#830909",
        ]);

        Priority::create([
            "name"    => "Normal",
            "color"    => "#090909",
        ]);

        Priority::create([
            "name"    => "Low",
            "color"    => "#125f71",
        ]);

        $supportCategory = Category::create([
            "name"    => "Support",
            "color"    => "#000000",
        ]);

        $admin = \App\User::orderBy('id', 'desc')->first();
        $admin->forceUpdate(['ticketit_agent' => 1]);
        $supportCategory->agents()->attach($admin->id);


    }

    private function setUpMail(){
        $this->info("Ticketit installation completed!");
        $this->comment("Don't forget to configure mail in your .env file");
        $this->line("https://laravel.com/docs/master/mail");
    }

    private function removeInstaller(){
        if($this->confirm('Do you want to remove the installer script?')){

            file_put_contents(app_path('Console/Kernel.php'), str_replace(
                'Commands\InstallTicketit::class,',
                '',
                file_get_contents(app_path('Console/Kernel.php'))
            ));

            unlink(app_path('Console/Commands/InstallTicketit.php'));
        }
    }

}
