# GptController Documentation

## Introduction
The GptController class provides an interface to interact with OpenAI's GPT models. It offers methods to initialize the client, fetch available models, generate completions, and interact with the chat-based API.

## Class Properties
- **public static $config**  
  **Description**: Holds the configuration settings for the class, including API keys and other essential parameters.

- **public static $roles**  
  **Description**: An associative array that defines the roles used in chat-based completions. This helps in structuring the conversation between the system, user, assistant, and any defined functions.

- **public $user**  
  **Description**: Represents the user or system interacting with the GPT model. It's used to identify the sender of a message in chat-based interactions.

- **private static $weightsMap**  
  **Description**: An associative array that maps predefined weights to their respective token limits. This aids in controlling the length and detail of the generated response.

- **private $client**  
  **Description**: An instance of the OpenAI client, initialized with the provided API key. This client facilitates all interactions with the OpenAI API.

## Methods
1. **__construct($config)**  
  **Description**: Initializes the class with the provided configuration, sets up roles, and initializes the OpenAI client.  
  **Parameters**:  
  `$config`: Configuration array containing the API key and other settings.

2. **init()**  
  **Description**: Initializes the OpenAI client with the API key from the configuration.

3. **getAllGptModels()**  
  **Description**: Fetches a list of all available GPT models from OpenAI.  
  **Returns**: Array of model names.

4. **getLatestGptModel()**  
  **Description**: Determines and returns the latest available GPT model.  
  **Returns**: Name of the latest model.

5. **testCompletionCreate()**  
  **Description**: A test method to generate a text completion based on a given prompt and weight.  
  **Output**: Prints the generated response.

6. **testChatCompletionCreate()**  
  **Description**: A test method to demonstrate chat-based completions using a set of predefined messages.  
  **Output**: Prints the chat response.

7. **testChatCompletionCreateWithFunction()**  
  **Description**: A test method that showcases chat-based completions with function calls, allowing for more dynamic interactions.  
  **Output**: Prints the chat response, including function call results.

8. **completionCreate($prompt, $weight = 0)**  
  **Description**: Generates a text completion based on the provided prompt and weight.  
  **Parameters**:  
  `$prompt`: The input text to generate a completion for.  
  `$weight`: Determines the maximum tokens for the completion.  
  **Returns**: Array containing the generated text.

9. **chatCompletionCreate($messages, $weight = 0, $functions = [])**  
  **Description**: Engages in a chat-based interaction using the provided messages and optional functions. Returns the model's response.  
  **Parameters**:  
  `$messages`: Array of message objects for the chat.  
  `$weight`: Determines the maximum tokens for the completion.  
  `$functions`: Optional array of function definitions.  
  **Returns**: Chat response.

## Usage
To utilize the GptController, first ensure that the OpenAI client library is properly installed and available in your project. Then, instantiate the class with the necessary configuration:
```php
$controller = new GptController($config);

Once instantiated, you can call any of the available methods to interact with the GPT models.

## Conclusion
The GptController class offers a robust and efficient way to harness the capabilities of OpenAI's GPT models within your application. By abstracting the complexities of direct API interactions, it allows developers to focus on building rich and interactive features powered by state-of-the-art language models.
