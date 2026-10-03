export async function buildHistory(client, messages) {
  const history = [];
  for (const message of messages) {
    const content = [];
    for (const part of (typeof message.content === 'string' ? [{type:'text',text:message.content}] : message.content)) {
      if (part.type === 'text') content.push(part);
      else if (part.type === 'image_path') {
        const image = await client.files.prepareImage(part.path);
        content.push({type:'file', name:image.name, identifier:image.identifier, sizeBytes:image.sizeBytes, fileType:image.type});
      } else throw Error('Unsupported message part');
    }
    history.push({role:message.role, content});
  }
  return {messages:history};
}

export function predictionConfig(payload) {
  return {
    temperature:payload.temperature,
    maxTokens:payload.max_tokens || false,
    userMaxImageDimensionPixels:false,
    ignoreModelPreferredMaxImageDimension:true,
    contextOverflowPolicy:'stopAtLimit',
    structured:{type:'json', jsonSchema:payload.response_format.json_schema.schema},
  };
}

export function finishReason(stop) {
  return ['eosFound','stopStringFound'].includes(stop) ? 'stop' : stop === 'maxPredictedTokensReached' ? 'length' : stop;
}
